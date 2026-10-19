// Test de bout en bout des fonctionnalités Elementor Pro dans Atelier MCP
// (Theme Builder, Conditions d'affichage, Widgets Globaux, Popups).
import assert from 'node:assert/strict';

const BASE = process.env.ATELIER_URL ?? 'http://localhost:8888';
const ENDPOINT = `${BASE}/wp-json/atelier/mcp`;
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

let createdHeaderId = null;
let createdWidgetId = null;
let createdPopupId = null;

// 1. Initialisation de session MCP
check('initialisation de la session MCP', async () => {
	const init = await rpc('initialize', {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: { name: 'atelier-pro-smoke', version: '1.0.0' },
	});
	assert.equal(init.status, 200);
	assert.ok(init.session);
	session = init.session;
	protocolVersion = init.json.result.protocolVersion;
	await rpc('notifications/initialized');
});

// 2. Theme Builder : locations & syntax
check('theme-builder: action=locations liste les emplacements et la syntaxe des conditions', async () => {
	const { data } = await callTool('atelier-theme-builder', { action: 'locations' });
	assert.ok(data.locations.header, 'emplacement header absent');
	assert.ok(data.locations.footer, 'emplacement footer absent');
	assert.ok(data.locations.popup, 'emplacement popup absent');
	assert.ok(data.condition_syntax['include/general'], 'syntaxe include/general absente');
	assert.ok(data.condition_syntax['include/singular/page'], 'syntaxe page absente');
});

// 3. Theme Builder : create template
check('theme-builder: action=create crée un header Theme Builder avec conditions', async () => {
	const { data } = await callTool('atelier-theme-builder', {
		action: 'create',
		title: 'Header Global Test',
		type: 'header',
		conditions: ['include/general'],
		elements: [
			{
				id: 'hdr01',
				elType: 'container',
				settings: { container_type: 'flex' },
				elements: [
					{
						id: 'logo01',
						elType: 'widget',
						widgetType: 'heading',
						settings: { title: 'Mon Site' },
					},
				],
			},
		],
	});

	assert.ok(data.id, 'id manquant');
	assert.equal(data.template_type, 'header');
	assert.deepEqual(data.display_conditions, ['include/general']);
	assert.ok(data.preview_url, 'preview_url absent');
	createdHeaderId = data.id;
});

// 4. Theme Builder : list templates
check('theme-builder: action=list retrouve le template créé', async () => {
	const { data } = await callTool('atelier-theme-builder', { action: 'list', type: 'header' });
	assert.ok(data.total >= 1);
	const found = data.templates.find((t) => t.id === createdHeaderId);
	assert.ok(found, 'header créé introuvable dans la liste');
	assert.equal(found.template_type, 'header');
	assert.deepEqual(found.display_conditions, ['include/general']);
});

// 5. Theme Builder : set conditions
check('theme-builder: action=set_conditions met à jour les règles d\'affichage', async () => {
	const newConditions = ['include/singular/page', 'exclude/singular/page/42'];
	const { data } = await callTool('atelier-theme-builder', {
		action: 'set_conditions',
		template_id: createdHeaderId,
		conditions: newConditions,
	});

	assert.equal(data.id, createdHeaderId);
	assert.deepEqual(data.display_conditions, newConditions);
});

// 6. Theme Builder : validation d'erreur
check('theme-builder: refuse set_conditions sans template_id valide', async () => {
	const res = await rpc('tools/call', {
		name: 'atelier-theme-builder',
		arguments: { action: 'set_conditions', template_id: 0, conditions: ['include/general'] },
	});
	const isError = res.json?.error || res.json?.result?.isError;
	assert.ok(isError, 'une erreur de validation était attendue');
});

// 7. Global Widgets : create
check('global-widgets: action=create crée un widget global réutilisable', async () => {
	const { data } = await callTool('atelier-global-widgets', {
		action: 'create',
		title: 'Bannière Promo Globale',
		widget_element: {
			elType: 'widget',
			widgetType: 'heading',
			settings: {
				title: 'Offre Spéciale -20%',
			},
		},
	});

	assert.ok(data.id, 'id du widget global manquant');
	assert.equal(data.widget_type, 'heading');
	assert.equal(data.usage_snippet.elType, 'widget');
	assert.equal(data.usage_snippet.widgetType, 'global');
	assert.equal(data.usage_snippet.templateID, data.id);
	createdWidgetId = data.id;
});

// 8. Global Widgets : list
check('global-widgets: action=list liste les widgets globaux', async () => {
	const { data } = await callTool('atelier-global-widgets', { action: 'list' });
	assert.ok(data.total >= 1);
	const found = data.widgets.find((w) => w.id === createdWidgetId);
	assert.ok(found, 'widget global créé introuvable dans la liste');
	assert.equal(found.widget_type, 'heading');
	assert.equal(found.usage_code.templateID, createdWidgetId);
});

// 9. Global Widgets : get
check('global-widgets: action=get retourne la configuration exacte et le snippet d\'usage', async () => {
	const { data } = await callTool('atelier-global-widgets', { action: 'get', id: createdWidgetId });
	assert.equal(data.id, createdWidgetId);
	assert.equal(data.widget?.settings?.title, 'Offre Spéciale -20%');
	assert.equal(data.usage_snippet?.widgetType, 'global');
	assert.equal(data.usage_snippet?.templateID, createdWidgetId);
});

// 10. Popups : create
check('popups: action=create crée un popup avec déclencheurs et conditions', async () => {
	const { data } = await callTool('atelier-popups', {
		action: 'create',
		title: 'Popup Inscription Newsletter',
		triggers: {
			page_load: true,
			exit_intent: true,
		},
		timing: {
			views_count: 3,
		},
		conditions: ['include/general'],
		elements: [
			{
				id: 'pop01',
				elType: 'container',
				elements: [
					{
						id: 'pophead',
						elType: 'widget',
						widgetType: 'heading',
						settings: { title: 'Abonnez-vous !' },
					},
				],
			},
		],
	});

	assert.ok(data.id, 'id de popup manquant');
	assert.equal(data.triggers?.page_load, true);
	assert.equal(data.triggers?.exit_intent, true);
	assert.equal(data.timing?.views_count, 3);
	assert.deepEqual(data.display_conditions, ['include/general']);
	createdPopupId = data.id;
});

// 11. Popups : configure
check('popups: action=configure met à jour les déclencheurs du popup', async () => {
	const { data } = await callTool('atelier-popups', {
		action: 'configure',
		popup_id: createdPopupId,
		triggers: {
			scroll: true,
		},
	});

	assert.equal(data.id, createdPopupId);
	assert.equal(data.settings?.scroll, true);
	assert.equal(data.settings?.exit_intent, true); // Conservation des réglages existants
});

// 12. Popups : list
check('popups: action=list liste les popups configurés', async () => {
	const { data } = await callTool('atelier-popups', { action: 'list' });
	assert.ok(data.total >= 1);
	const found = data.popups.find((p) => p.id === createdPopupId);
	assert.ok(found, 'popup créé introuvable dans la liste');
	assert.equal(found.triggers?.scroll, true);
	assert.equal(found.triggers?.exit_intent, true);
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

console.log(`\n${passed}/${checks.length} vérifications Elementor Pro réussies`);
