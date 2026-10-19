// Test de bout en bout des fonctionnalités Gutenberg dans Atelier MCP
// (Lecture, écriture avec concurrence optimiste, blocs installés, patterns, theme.json).
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

let currentModifiedGmt = null;
let samplePatternName = null;

// 1. Initialisation de session MCP
check('initialisation de la session MCP', async () => {
	const init = await rpc('initialize', {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: { name: 'atelier-gutenberg-smoke', version: '1.0.0' },
	});
	assert.equal(init.status, 200);
	assert.ok(init.session);
	session = init.session;
	protocolVersion = init.json.result.protocolVersion;
	await rpc('notifications/initialized');
});

// 2. list-documents : détection de l'éditeur Gutenberg
check('list-documents détecte le document avec editor=gutenberg', async () => {
	const { data } = await callTool('atelier-list-documents', { editor: 'gutenberg', post_type: 'page' });
	const doc = data.documents.find((d) => d.id === PAGE_ID);
	assert.ok(doc, 'page de test Gutenberg introuvable');
	assert.equal(doc.editor, 'gutenberg');
});

// 3. theme-json : section all
check('theme-json: section=all lit les couleurs, la typographie et la mise en page', async () => {
	const { data } = await callTool('atelier-theme-json', { section: 'all' });
	assert.ok(data.colors, 'section colors manquante');
	assert.ok(data.typography, 'section typography manquante');
	assert.ok(data.layout, 'section layout manquante');
	assert.ok(Array.isArray(data.colors.palette), 'palette de couleurs attendue sous forme de tableau');
});

// 4. theme-json : section templates
check('theme-json: section=templates liste les modèles de thèmes à blocs', async () => {
	const { data } = await callTool('atelier-theme-json', { section: 'templates' });
	assert.ok(data.total >= 1, 'aucun modèle de bloc trouvé');
	assert.ok(data.templates.some((t) => t.slug === 'index' || t.slug === 'page' || t.slug === 'single'), 'modèle standard introuvable');
});

// 5. gutenberg-blocks : action=list
check('gutenberg-blocks: action=list liste les types de blocs natifs', async () => {
	const { data } = await callTool('atelier-gutenberg-blocks', { action: 'list' });
	assert.ok(data.total > 20, 'nombre insuffisant de blocs listés');
	assert.ok(data.blocks.some((b) => b.name === 'core/paragraph'));
	assert.ok(data.blocks.some((b) => b.name === 'core/heading'));
	assert.ok(data.blocks.some((b) => b.name === 'core/columns'));
});

// 6. gutenberg-blocks : action=describe
check('gutenberg-blocks: action=describe inspecte les attributs d\'un bloc', async () => {
	const { data } = await callTool('atelier-gutenberg-blocks', { action: 'describe', name: 'core/heading' });
	assert.equal(data.name, 'core/heading');
	assert.ok(data.attributes.level, 'attribut level manquant sur core/heading');
	assert.ok(data.supports, 'supports manquant sur core/heading');
});

// 7. gutenberg-patterns : action=list & categories
check('gutenberg-patterns: action=list et categories liste les compositions', async () => {
	const cats = await callTool('atelier-gutenberg-patterns', { action: 'categories' });
	assert.ok(cats.data.total >= 1, 'catégories de patterns absentes');

	const list = await callTool('atelier-gutenberg-patterns', { action: 'list' });
	assert.ok(list.data.total >= 1, 'compositions absentes');
	samplePatternName = list.data.patterns[0].name;
	assert.ok(samplePatternName);
});

// 8. gutenberg-patterns : action=get
check('gutenberg-patterns: action=get extrait la structure complète d\'un pattern', async () => {
	const { data } = await callTool('atelier-gutenberg-patterns', { action: 'get', name: samplePatternName });
	assert.equal(data.name, samplePatternName);
	assert.ok(data.content, 'contenu brut du pattern manquant');
	assert.ok(data.stats, 'statistiques du pattern manquantes');
	assert.ok(typeof data.outline === 'string', 'outline textuel attendu');
});

// 9. gutenberg-document : lecture outline et format blocks
check('gutenberg-document: lit le document en format outline et blocks', async () => {
	const outlineRes = await callTool('atelier-gutenberg-document', { id: PAGE_ID, format: 'outline' });
	assert.ok(outlineRes.data.modified_gmt, 'modified_gmt absent');
	currentModifiedGmt = outlineRes.data.modified_gmt;
	assert.match(outlineRes.data.outline, /core\/heading \[h1\] « Bienvenue sur Gutenberg »/);
	assert.equal(outlineRes.data.stats.total_blocks, 2);

	const blocksRes = await callTool('atelier-gutenberg-document', { id: PAGE_ID, format: 'blocks' });
	assert.equal(blocksRes.data.blocks.length, 2);
	assert.equal(blocksRes.data.blocks[0].blockName, 'core/heading');
	assert.equal(blocksRes.data.blocks[0].attrs.level, 1);
});

// 10. gutenberg-save : mode dry_run
check('gutenberg-save: dry_run=true simule la modification sans altérer le document', async () => {
	const simulatedBlocks = [
		{
			blockName: 'core/heading',
			attrs: { level: 2 },
			innerHTML: '<h2>Nouveau sous-titre simulé</h2>',
			innerContent: ['<h2>Nouveau sous-titre simulé</h2>'],
		},
	];

	const { data } = await callTool('atelier-gutenberg-save', {
		id: PAGE_ID,
		modified_gmt: currentModifiedGmt,
		blocks: simulatedBlocks,
		dry_run: true,
	});

	assert.equal(data.dry_run, true);
	assert.match(data.outline, /core\/heading \[h2\] « Nouveau sous-titre simulé »/);

	// Vérifier que le document en base n'a pas été modifié
	const checkDoc = await callTool('atelier-gutenberg-document', { id: PAGE_ID });
	assert.match(checkDoc.data.outline, /Bienvenue sur Gutenberg/);
});

// 11. gutenberg-save : protection anti-concurrence
check('gutenberg-save: refuse une modification concurrente (modified_gmt périmé)', async () => {
	const res = await rpc('tools/call', {
		name: 'atelier-gutenberg-save',
		arguments: {
			id: PAGE_ID,
			modified_gmt: '2020-01-01 00:00:00', // Date périmée
			raw_content: '<!-- wp:paragraph --><p>Test écrasement</p><!-- /wp:paragraph -->',
		},
	});

	const isError = res.json?.error || res.json?.result?.isError;
	assert.ok(isError, 'rejet pour conflit 409 attendu');
});

// 12. gutenberg-save : persistance réelle et révision
check('gutenberg-save: persiste l\'arbre de blocs et crée une révision', async () => {
	const newBlocks = [
		{
			blockName: 'core/heading',
			attrs: { level: 2 },
			innerHTML: '<h2>Structure Mise à Jour</h2>',
			innerContent: ['<h2>Structure Mise à Jour</h2>'],
		},
		{
			blockName: 'core/paragraph',
			attrs: {},
			innerHTML: '<p>Ce contenu a été généré via le protocole MCP.</p>',
			innerContent: ['<p>Ce contenu a été généré via le protocole MCP.</p>'],
		},
	];

	const saveRes = await callTool('atelier-gutenberg-save', {
		id: PAGE_ID,
		modified_gmt: currentModifiedGmt,
		blocks: newBlocks,
		create_revision: true,
	});

	assert.ok(saveRes.data.revision_id, 'id de révision attendu');
	assert.match(saveRes.data.outline, /Structure Mise à Jour/);

	const readDoc = await callTool('atelier-gutenberg-document', { id: PAGE_ID });
	assert.match(readDoc.data.outline, /Structure Mise à Jour/);
	assert.match(readDoc.data.outline, /Ce contenu a été généré/);

	const rawDoc = await callTool('atelier-gutenberg-document', { id: PAGE_ID, format: 'raw' });
	assert.match(rawDoc.data.raw_content, /Ce contenu a été généré via le protocole MCP/);
});

// Exécution séquentielle
let passed = 0;
for (const { label, fn } of checks) {
	try {
		await fn();
		console.log(`ok   ${label}`);
		passed++;
	} catch (err) {
		console.error(`FAIL ${label}`);
		console.error(err);
		process.exit(1);
	}
}

console.log(`\n${passed}/${checks.length} vérifications Gutenberg réussies`);
