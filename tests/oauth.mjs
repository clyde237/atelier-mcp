// Test de bout en bout du serveur OAuth 2.1 et de l'authentification Bearer Atelier MCP.
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { execSync } from 'node:child_process';

const BASE = process.env.ATELIER_URL ?? 'http://localhost:8888';
const MCP_ENDPOINT = `${BASE}/wp-json/atelier/mcp`;
const OAUTH_BASE = `${BASE}/wp-json/atelier/oauth`;

function base64UrlEncode(buffer) {
	return buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function generatePkce() {
	const verifier = base64UrlEncode(crypto.randomBytes(32));
	const challenge = base64UrlEncode(crypto.createHash('sha256').update(verifier).digest());
	return { verifier, challenge };
}

function injectAuthCode(codeHash, clientId, challenge, redirectUri, userId = 1, scopes = ['atelier:read', 'atelier:write']) {
	const scopesPhp = `array(${scopes.map(s => `'${s}'`).join(', ')})`;
	const php = `set_transient('atelier_oauth_code_${codeHash}', array('client_id' => '${clientId}', 'user_id' => ${userId}, 'redirect_uri' => '${redirectUri}', 'scopes' => ${scopesPhp}, 'code_challenge' => '${challenge}', 'created_at' => time()), 300);`;
	execSync(`bash dev/env.sh wp eval "${php.replace(/"/g, '\\"')}" 2>/dev/null`);
}

const checks = [];
function check(label, fn) {
	checks.push({ label, fn });
}

// 1. Découverte RFC 9728 sur réponse 401
check('réponse 401 sur /atelier/mcp inclut le header WWW-Authenticate avec resource_metadata', async () => {
	const res = await fetch(MCP_ENDPOINT, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list' }),
	});
	assert.equal(res.status, 401);
	const wwwAuth = res.headers.get('www-authenticate');
	assert.ok(wwwAuth, 'Header WWW-Authenticate manquant');
	assert.match(wwwAuth, /Bearer resource_metadata=".*oauth-protected-resource"/);
	assert.match(wwwAuth, /scope="mcp:tools atelier:read atelier:write"/);
});

// 1b. En-têtes CORS et prévol OPTIONS
check('prévol OPTIONS renvoie les en-têtes CORS nécessaires à Claude.ai', async () => {
	const res = await fetch(`${OAUTH_BASE}/token`, {
		method: 'OPTIONS',
	});
	assert.equal(res.status, 204);
	assert.equal(res.headers.get('access-control-allow-origin'), '*');
	const allowHeaders = res.headers.get('access-control-allow-headers');
	assert.ok(allowHeaders && allowHeaders.includes('Authorization'));
});

// 2. Métadonnées RFC 9728
check('métadonnées de la ressource protégée (RFC 9728)', async () => {
	const res = await fetch(`${OAUTH_BASE}/protected-resource`);
	assert.equal(res.status, 200);
	const data = await res.json();
	assert.equal(data.resource, MCP_ENDPOINT);
	assert.ok(Array.isArray(data.authorization_servers));
	assert.ok(Array.isArray(data.scopes_supported));
	assert.ok(data.scopes_supported.includes('atelier:read'));
	assert.ok(data.scopes_supported.includes('atelier:write'));
	assert.ok(data.scopes_supported.includes('mcp:tools'));
	assert.ok(data.scopes_supported.includes('offline_access'));
});

// 3. Métadonnées RFC 8414
check('métadonnées du serveur d\'autorisation (RFC 8414)', async () => {
	const res = await fetch(`${OAUTH_BASE}/authorization-server`);
	assert.equal(res.status, 200);
	const data = await res.json();
	assert.equal(data.issuer, BASE);
	assert.ok(data.authorization_endpoint.includes('/authorize'));
	assert.ok(data.token_endpoint.includes('/token'));
	assert.ok(data.registration_endpoint.includes('/register'));
	assert.deepEqual(data.code_challenge_methods_supported, ['S256']);
	assert.ok(data.grant_types_supported.includes('authorization_code'));
	assert.ok(data.grant_types_supported.includes('refresh_token'));
	assert.ok(data.scopes_supported.includes('mcp:tools'));
	assert.ok(data.scopes_supported.includes('offline_access'));
});

// 4. Dynamic Client Registration (RFC 7591)
let dynamicClient;
check('enregistrement dynamique de client (DCR RFC 7591)', async () => {
	const res = await fetch(`${OAUTH_BASE}/register`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			client_name: 'Claude Test Runner',
			redirect_uris: ['https://claude.ai/api/mcp/auth_callback'],
			token_endpoint_auth_method: 'none',
		}),
	});
	assert.equal(res.status, 201);
	const data = await res.json();
	assert.ok(data.client_id.startsWith('at_client_'));
	assert.equal(data.client_name, 'Claude Test Runner');
	dynamicClient = data;
});

check('enregistrement DCR refuse une requête sans redirect_uris', async () => {
	const res = await fetch(`${OAUTH_BASE}/register`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			client_name: 'Invalide',
			redirect_uris: [],
		}),
	});
	assert.equal(res.status, 400);
});

// 5. Flux d'échange de code avec PKCE (S256)
let oauthTokens;
const pkce = generatePkce();

check('échange de code refuse un verifier PKCE incorrect', async () => {
	const authCode = crypto.randomBytes(16).toString('hex');
	const codeHash = crypto.createHash('sha256').update(authCode).digest('hex');

	injectAuthCode(codeHash, dynamicClient.client_id, pkce.challenge, 'https://claude.ai/api/mcp/auth_callback');

	const res = await fetch(`${OAUTH_BASE}/token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			grant_type: 'authorization_code',
			client_id: dynamicClient.client_id,
			code: authCode,
			redirect_uri: 'https://claude.ai/api/mcp/auth_callback',
			code_verifier: 'mauvais_verifier_qui_ne_correspond_pas',
		}),
	});
	assert.equal(res.status, 400);
	const err = await res.json();
	assert.equal(err.code, 'invalid_grant');
});

check('échange de code réussit avec PKCE S256 valide et émet des tokens', async () => {
	const authCode = crypto.randomBytes(16).toString('hex');
	const codeHash = crypto.createHash('sha256').update(authCode).digest('hex');

	injectAuthCode(codeHash, dynamicClient.client_id, pkce.challenge, 'https://claude.ai/api/mcp/auth_callback');

	const res = await fetch(`${OAUTH_BASE}/token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			grant_type: 'authorization_code',
			client_id: dynamicClient.client_id,
			code: authCode,
			redirect_uri: 'https://claude.ai/api/mcp/auth_callback',
			code_verifier: pkce.verifier,
		}),
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.ok(data.access_token.startsWith('at_live_'));
	assert.equal(data.token_type, 'Bearer');
	assert.ok(data.refresh_token.startsWith('at_rf_'));
	assert.ok(data.expires_in > 0);
	oauthTokens = data;
});

// 6. Accès au serveur MCP avec le Bearer token OAuth émis
check('authentification sur le serveur MCP avec le jeton Bearer OAuth', async () => {
	const res = await fetch(MCP_ENDPOINT, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${oauthTokens.access_token}`,
		},
		body: JSON.stringify({
			jsonrpc: '2.0',
			id: 10,
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'claude-oauth-test', version: '1.0' },
			},
		}),
	});

	assert.equal(res.status, 200, `Attendu 200, reçu ${res.status}`);
	const json = await res.json();
	assert.ok(json.result?.instructions, 'Session MCP non initialisée');
});

// 6b. Accès avec jeton ayant uniquement la portée mcp:tools (comme Claude.ai)
check('authentification MCP avec un jeton ayant uniquement la portée mcp:tools', async () => {
	const authCode = crypto.randomBytes(16).toString('hex');
	const codeHash = crypto.createHash('sha256').update(authCode).digest('hex');
	const specificPkce = generatePkce();

	injectAuthCode(codeHash, dynamicClient.client_id, specificPkce.challenge, 'https://claude.ai/api/mcp/auth_callback', 1, ['mcp:tools']);

	const tokenRes = await fetch(`${OAUTH_BASE}/token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			grant_type: 'authorization_code',
			code: authCode,
			client_id: dynamicClient.client_id,
			redirect_uri: 'https://claude.ai/api/mcp/auth_callback',
			code_verifier: specificPkce.verifier,
		}),
	});
	assert.equal(tokenRes.status, 200);
	const data = await tokenRes.json();
	assert.ok(data.access_token);

	const mcpRes = await fetch(MCP_ENDPOINT, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${data.access_token}`,
		},
		body: JSON.stringify({
			jsonrpc: '2.0',
			id: 11,
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'claude-mcp-tools-only', version: '1.0' },
			},
		}),
	});

	assert.equal(mcpRes.status, 200);
	const json = await mcpRes.json();
	assert.ok(json.result?.instructions);
});

// 7. Rotation du Refresh Token
let rotatedTokens;
check('rotation du refresh token (OAuth 2.1)', async () => {
	const res = await fetch(`${OAUTH_BASE}/token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			grant_type: 'refresh_token',
			client_id: dynamicClient.client_id,
			refresh_token: oauthTokens.refresh_token,
		}),
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.ok(data.access_token);
	assert.ok(data.refresh_token);
	assert.notEqual(data.access_token, oauthTokens.access_token, 'Le nouvel access token doit être différent');
	assert.notEqual(data.refresh_token, oauthTokens.refresh_token, 'Le nouveau refresh token doit avoir tourné');
	rotatedTokens = data;
});

check('l\'ancien refresh token ne peut plus être réutilisé', async () => {
	const res = await fetch(`${OAUTH_BASE}/token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			grant_type: 'refresh_token',
			client_id: dynamicClient.client_id,
			refresh_token: oauthTokens.refresh_token,
		}),
	});
	assert.equal(res.status, 400);
});

// 8. Révocation RFC 7009
check('révocation de jeton (RFC 7009) et rejet immédiat', async () => {
	const resRevoke = await fetch(`${OAUTH_BASE}/revoke`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({ token: rotatedTokens.access_token }),
	});
	assert.equal(resRevoke.status, 200);

	// Vérifier que le token révoqué ne peut plus accéder au serveur MCP
	const resMcp = await fetch(MCP_ENDPOINT, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${rotatedTokens.access_token}`,
		},
		body: JSON.stringify({ jsonrpc: '2.0', id: 11, method: 'tools/list' }),
	});
	assert.equal(resMcp.status, 401);
});

// 9. Personal Access Token (PAT)
check('authentification MCP avec un Personal Access Token (PAT)', async () => {
	const patToken = process.env.ATELIER_TEST_PAT;
	assert.ok(patToken, 'ATELIER_TEST_PAT manquant');

	const res = await fetch(MCP_ENDPOINT, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${patToken}`,
		},
		body: JSON.stringify({
			jsonrpc: '2.0',
			id: 20,
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'pat-test', version: '1.0' },
			},
		}),
	});

	assert.equal(res.status, 200);
	const json = await res.json();
	assert.ok(json.result);
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

console.log(`\n${checks.length - failures}/${checks.length} vérifications OAuth réussies`);
process.exit(failures ? 1 : 0);
