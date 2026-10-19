// Test de bout en bout des opérations d'écriture Atelier MCP (save, create, restore, media, cache, preview).
import assert from 'node:assert/strict';

const BASE = process.env.ATELIER_URL ?? 'http://localhost:8888';
const ENDPOINT = `${BASE}/wp-json/atelier/mcp`;
const PAGE_ID = Number(process.env.ATELIER_PAGE_ID);
const ADMIN_AUTH = 'Basic ' + Buffer.from(`${process.env.ATELIER_ADMIN_USER}:${process.env.ATELIER_ADMIN_PASSWORD}`).toString('base64');

let rpcId = 0;
let protocolVersion = null;
let session = null;

async function rpc(method, params = {}) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		Authorization: ADMIN_AUTH,
	};
	if (session) headers['Mcp-Session-Id'] = session;
	if (session && protocolVersion) headers['MCP-Protocol-Version'] = protocolVersion;
	const isNotification = method.startsWith('notifications/');
	const body = { jsonrpc: '2.0', method, params, ...(isNotification ? {} : { id: ++rpcId }) };
	const response = await fetch(ENDPOINT, { method: 'POST', headers, body: JSON.stringify(body) });
	const text = await response.text();
	let json = null;
	if (text) {
		const payload = text.trim().startsWith('{') ? text : text.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5)).join('');
		json = payload ? JSON.parse(payload) : null;
	}
	return { status: response.status, session: response.headers.get('mcp-session-id'), json };
}

async function callTool(name, args = {}) {
	const res = await rpc('tools/call', { name, arguments: args });
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

// Initialisation de session MCP
check('initialisation de la session MCP', async () => {
	const init = await rpc('initialize', {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: { name: 'atelier-write-smoke', version: '1.0.0' },
	});
	assert.equal(init.status, 200);
	assert.ok(init.session);
	session = init.session;
	protocolVersion = init.json.result.protocolVersion;
	await rpc('notifications/initialized');
});

let initialDoc;
let initialElements;

check('lecture initiale du document de test', async () => {
	const { data } = await callTool('atelier-get-document', { id: PAGE_ID, format: 'full' });
	assert.ok(data.id === PAGE_ID);
	assert.ok(data.modified_gmt, 'modified_gmt manquant');
	assert.ok(Array.isArray(data.elements));
	initialDoc = data;
	initialElements = JSON.parse(JSON.stringify(data.elements));
});

check('save-document en mode dry_run ne modifie pas le document', async () => {
	const mutatedElements = JSON.parse(JSON.stringify(initialElements));
	// Modification du titre
	const heading = mutatedElements[0].elements.find((el) => el.id === 'h1a');
	if (heading) {
		heading.settings.title = 'Titre Modifié en Simulation';
	}

	const { data } = await callTool('atelier-save-document', {
		id: PAGE_ID,
		modified_gmt: initialDoc.modified_gmt,
		elements: mutatedElements,
		dry_run: true,
	});

	assert.equal(data.dry_run, true);
	assert.equal(data.valid, true);
	assert.ok(data.audit);

	// Vérifier que le document réel n'a pas bougé
	const checkDoc = await callTool('atelier-get-document', { id: PAGE_ID, format: 'full' });
	assert.equal(checkDoc.data.modified_gmt, initialDoc.modified_gmt, 'modified_gmt ne doit pas changer en dry_run');
	const currentHeading = checkDoc.data.elements[0].elements.find((el) => el.id === 'h1a');
	assert.equal(currentHeading?.settings?.title, 'Titre principal', 'Le contenu en base ne doit pas avoir changé');
});

check('save-document refuse un modified_gmt périmé (protection anti-écrasement)', async () => {
	const res = await rpc('tools/call', {
		name: 'atelier-save-document',
		arguments: {
			id: PAGE_ID,
			modified_gmt: '2020-01-01 00:00:00', // Date périmée
			elements: initialElements,
		},
	});

	const isError = res.json?.error || res.json?.result?.isError;
	assert.ok(isError, 'Un conflit de version (409) devait être retourné');
});

let updatedDoc;
let createdRevisionId;

check('save-document persiste la modification et crée une révision', async () => {
	const mutatedElements = JSON.parse(JSON.stringify(initialElements));
	const heading = mutatedElements[0].elements.find((el) => el.id === 'h1a');
	if (heading) {
		heading.settings.title = 'Titre Modifié Réellement';
	}

	const { data } = await callTool('atelier-save-document', {
		id: PAGE_ID,
		modified_gmt: initialDoc.modified_gmt,
		elements: mutatedElements,
		create_revision: true,
	});

	assert.equal(data.success, true);
	assert.ok(data.modified_gmt);
	assert.ok(data.preview_url);
	assert.ok(data.revision_id, 'Révision WordPress attendue');
	updatedDoc = data;
	createdRevisionId = data.revision_id;

	// Vérifier que la lecture full reflète bien le nouveau titre
	const checkDoc = await callTool('atelier-get-document', { id: PAGE_ID, format: 'full' });
	assert.equal(checkDoc.data.modified_gmt, data.modified_gmt);
	const currentHeading = checkDoc.data.elements[0].elements.find((el) => el.id === 'h1a');
	assert.equal(currentHeading?.settings?.title, 'Titre Modifié Réellement');
});

check('preview-link génère une URL signée accessible sans authentification', async () => {
	const { data } = await callTool('atelier-preview-link', { id: PAGE_ID });
	assert.ok(data.preview_url);

	// Appel HTTP public sans aucun header Authorization
	const res = await fetch(data.preview_url);
	assert.equal(res.status, 200, `L'aperçu signé doit répondre HTTP 200 (reçu ${res.status})`);
});

check('list-revisions retourne l\'historique des révisions du document', async () => {
	const { data } = await callTool('atelier-list-revisions', { id: PAGE_ID });
	assert.ok(data.total >= 1);
	const found = data.revisions.some((r) => r.revision_id === createdRevisionId);
	assert.ok(found, `La révision ${createdRevisionId} doit être présente dans la liste`);
});

check('restore-revision restaure le contenu antérieur', async () => {
	const { data } = await callTool('atelier-restore-revision', {
		id: PAGE_ID,
		revision_id: createdRevisionId,
	});

	assert.ok(data.restored_revision_id === createdRevisionId);
	assert.ok(data.modified_gmt);

	// Re-sauvegarder l'état initial pour laisser l'environnement propre
	await callTool('atelier-save-document', {
		id: PAGE_ID,
		modified_gmt: data.modified_gmt,
		elements: initialElements,
	});
});

let createdPageId;
check('create-document crée une nouvelle page Elementor', async () => {
	const { data } = await callTool('atelier-create-document', {
		title: 'Page Test Créée par Atelier',
		type: 'wp-page',
		status: 'draft',
		elements: [
			{
				id: 'cnt01',
				elType: 'container',
				settings: { flex_direction: 'column' },
				elements: [
					{
						id: 'hd01',
						elType: 'widget',
						widgetType: 'heading',
						settings: { title: 'Bienvenue sur la nouvelle page' },
					},
				],
			},
		],
	});

	assert.ok(data.id > 0);
	assert.equal(data.status, 'draft');
	assert.ok(data.preview_url);
	assert.equal(data.editor, 'elementor');
	createdPageId = data.id;
});

check('upload-media téléverse une image avec alt obligatoire dans la médiathèque', async () => {
	// Pixel PNG 1x1 transparent valide
	const pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	const { data } = await callTool('atelier-upload-media', {
		filename: 'atelier-test-pixel.png',
		content_base64: pngBase64,
		alt_text: 'Pixel transparent de test Atelier MCP',
		title: 'Pixel Test',
	});

	assert.ok(data.id > 0);
	assert.ok(data.url);
	assert.equal(data.alt, 'Pixel transparent de test Atelier MCP');
	assert.equal(data.mime_type, 'image/png');
});

check('flush-cache purge les caches Elementor avec succès', async () => {
	const { data } = await callTool('atelier-flush-cache');
	assert.equal(data.success, true);
	assert.ok(data.message);
});

check('activity-log conserve la trace de toutes les opérations effectuées', async () => {
	const { data } = await callTool('atelier-activity-log', { limit: 10 });
	assert.ok(data.total >= 4);
	const actions = new Set(data.entries.map((e) => e.action));

	assert.ok(actions.has('save_document'), 'save_document doit être consigné');
	assert.ok(actions.has('create_document'), 'create_document doit être consigné');
	assert.ok(actions.has('restore_revision'), 'restore_revision doit être consigné');
	assert.ok(actions.has('upload_media'), 'upload_media doit être consigné');
	assert.ok(actions.has('flush_cache'), 'flush_cache doit être consigné');
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

console.log(`\n${checks.length - failures}/${checks.length} vérifications d'écriture réussies`);
process.exit(failures ? 1 : 0);
