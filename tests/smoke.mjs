// Test de bout en bout du serveur MCP Atelier sur l'environnement local (dev/docker-compose.yml).
// Lancé par tests/smoke.sh, qui prépare les données et fournit les identifiants de test.
import assert from 'node:assert/strict';

const BASE = process.env.ATELIER_URL ?? 'http://localhost:8888';
const ENDPOINT = `${BASE}/wp-json/atelier/mcp`;
const PAGE_ID = Number(process.env.ATELIER_PAGE_ID);
const ADMIN_AUTH = 'Basic ' + Buffer.from(`${process.env.ATELIER_ADMIN_USER}:${process.env.ATELIER_ADMIN_PASSWORD}`).toString('base64');
const SUBSCRIBER_AUTH = 'Basic ' + Buffer.from(`${process.env.ATELIER_SUBSCRIBER_USER}:${process.env.ATELIER_SUBSCRIBER_PASSWORD}`).toString('base64');

let rpcId = 0;
// Version négociée à l'initialisation ; le transport l'exige ensuite sur chaque requête.
let protocolVersion = null;

async function rpc(auth, sessionId, method, params = {}) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		Authorization: auth,
	};
	if (sessionId) headers['Mcp-Session-Id'] = sessionId;
	if (sessionId && protocolVersion) headers['MCP-Protocol-Version'] = protocolVersion;
	const isNotification = method.startsWith('notifications/');
	const body = { jsonrpc: '2.0', method, params, ...(isNotification ? {} : { id: ++rpcId }) };
	const response = await fetch(ENDPOINT, { method: 'POST', headers, body: JSON.stringify(body) });
	const text = await response.text();
	let json = null;
	if (text) {
		// Le transport peut répondre en JSON ou en flux SSE ("data: {...}").
		const payload = text.trim().startsWith('{') ? text : text.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5)).join('');
		json = payload ? JSON.parse(payload) : null;
	}
	return { status: response.status, session: response.headers.get('mcp-session-id'), json };
}

async function openSession(auth) {
	const init = await rpc(auth, null, 'initialize', {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: { name: 'atelier-smoke', version: '0.0.0' },
	});
	return init;
}

async function callTool(session, name, args = {}) {
	const res = await rpc(ADMIN_AUTH, session, 'tools/call', { name, arguments: args });
	assert.equal(res.status, 200, `${name} : HTTP ${res.status}`);
	assert.ok(res.json?.result, `${name} : pas de résultat ${JSON.stringify(res.json)}`);
	const result = res.json.result;
	const structured = result.structuredContent ?? JSON.parse(result.content?.[0]?.text ?? 'null');
	return { result, data: structured };
}

const checks = [];
function check(label, fn) {
	checks.push({ label, fn });
}

check('refuse une requête sans authentification', async () => {
	const res = await rpc('', null, 'initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'x', version: '0' } });
	assert.ok([401, 403].includes(res.status), `statut ${res.status}`);
});

check('refuse un abonné (pas de droit edit_posts)', async () => {
	const res = await openSession(SUBSCRIBER_AUTH);
	assert.ok([401, 403].includes(res.status), `statut ${res.status}`);
});

let session;
check('ouvre une session et envoie les instructions', async () => {
	const init = await openSession(ADMIN_AUTH);
	assert.equal(init.status, 200, JSON.stringify(init.json));
	assert.ok(init.session, 'Mcp-Session-Id absent');
	assert.match(init.json.result.instructions, /Conteneurs uniquement/);
	session = init.session;
	protocolVersion = init.json.result.protocolVersion;
	await rpc(ADMIN_AUTH, session, 'notifications/initialized');
});

check('liste les 6 outils Atelier', async () => {
	const res = await rpc(ADMIN_AUTH, session, 'tools/list');
	const names = res.json.result.tools.map((t) => t.name).sort();
	assert.deepEqual(names, [
		'atelier-audit-document',
		'atelier-describe-widget',
		'atelier-get-document',
		'atelier-get-kit',
		'atelier-list-documents',
		'atelier-site-info',
	]);
	const readonly = res.json.result.tools.every((t) => t.annotations?.readOnlyHint === true);
	assert.ok(readonly, 'tous les outils doivent être marqués lecture seule');
});

check('site-info détecte Elementor et les points de rupture', async () => {
	const { data } = await callTool(session, 'atelier-site-info');
	assert.equal(data.elementor.active, true);
	assert.ok(data.elementor.breakpoints.mobile, 'point de rupture mobile absent');
	assert.ok(data.server?.php_version, 'admin : détails serveur attendus');
});

check('list-documents trouve la page de test Elementor', async () => {
	const { data } = await callTool(session, 'atelier-list-documents', { editor: 'elementor', post_type: 'page' });
	const page = data.documents.find((d) => d.id === PAGE_ID);
	assert.ok(page, 'page de test absente');
	assert.equal(page.editor, 'elementor');
});

check('list-documents refuse un type de contenu inconnu', async () => {
	const res = await rpc(ADMIN_AUTH, session, 'tools/call', { name: 'atelier-list-documents', arguments: { post_type: 'nexiste_pas' } });
	const isError = res.json?.error || res.json?.result?.isError;
	assert.ok(isError, 'une erreur était attendue');
});

check('get-document renvoie un plan lisible', async () => {
	const { data } = await callTool(session, 'atelier-get-document', { id: PAGE_ID });
	assert.match(data.outline, /container#hero01 \[flex column/);
	assert.match(data.outline, /heading#h1a \[h1\] « Titre principal »/);
	assert.equal(data.stats.by_type.section, 1);
	assert.ok(data.modified_gmt);
});

check('get-document renvoie un élément précis en format full', async () => {
	const { data } = await callTool(session, 'atelier-get-document', { id: PAGE_ID, format: 'full', element_id: 'grid01' });
	assert.equal(data.element.id, 'grid01');
	assert.equal(data.element.settings.container_type, 'grid');
});

check('describe-widget liste les widgets puis les réglages du bouton', async () => {
	const list = await callTool(session, 'atelier-describe-widget');
	assert.ok(list.data.widgets.some((w) => w.name === 'heading'));
	const button = await callTool(session, 'atelier-describe-widget', { type: 'button', search: 'background' });
	assert.ok(button.data.controls.some((line) => line.startsWith('background_color |')), button.data.controls.join('\n'));
	const container = await callTool(session, 'atelier-describe-widget', { type: 'container', search: 'grid_columns' });
	assert.ok(container.data.controls.some((line) => line.startsWith('grid_columns_grid |') && line.includes('mobile')), container.data.controls.join('\n'));
});

check('get-kit renvoie les couleurs globales', async () => {
	const { data } = await callTool(session, 'atelier-get-kit');
	assert.ok(data.kit_id > 0);
	assert.ok(Array.isArray(data.colors));
});

check('audit-document signale les problèmes connus de la page de test', async () => {
	const { data } = await callTool(session, 'atelier-audit-document', { id: PAGE_ID });
	const rules = new Set(data.findings.map((f) => `${f.rule}:${f.element}`));
	for (const expected of [
		'legacy-layout:',
		'html-widget:html01',
		'css-classes:col01',
		'multiple-h1:',
		'heading-skip:',
		'empty-link:btn01',
		'image-alt:img01',
		'row-not-stacking:row01',
		'grid-mobile-columns:grid01',
		'small-font-mobile:h4a',
		'tall-on-mobile:hero01',
	]) {
		assert.ok(rules.has(expected), `constat attendu absent : ${expected}\nobtenus : ${[...rules].join(', ')}`);
	}
	// Faux positifs à éviter : grille sans réglage mobile (le défaut mobile d'Elementor est 1 colonne)
	// et rangée déjà empilée sur mobile.
	assert.ok(!rules.has('grid-mobile-columns:grid02'), 'grid02 sans réglage mobile ne doit pas être signalée');
	assert.ok(!rules.has('row-not-stacking:row02'), 'row02 empilée sur mobile ne doit pas être signalée');
});

let failures = 0;
for (const { label, fn } of checks) {
	try {
		await fn();
		console.log(`ok   ${label}`);
	} catch (error) {
		failures++;
		console.log(`FAIL ${label}\n     ${error.message.split('\n').join('\n     ')}`);
	}
}
console.log(`\n${checks.length - failures}/${checks.length} vérifications réussies`);
process.exit(failures ? 1 : 0);
