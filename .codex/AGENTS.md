# Debugging and Testing Rules

Do not run the complete test suite unless explicitly requested.

Never run these commands automatically:

- php artisan test
- vendor/bin/phpunit
- composer test
- npm test
- npm run build

Do not create new tests unless explicitly requested.

For feature verification, prefer real user interaction diagnostics over PHPUnit/Pest mini-tests. Use scripts like `scripts/web-real-workflow-diagnostic.mjs` that log in, submit forms/requests the way the UI does, upload files, poll status, and report the real user-visible result.

When tests are explicitly requested, add the smallest number of high-signal user workflow or regression tests that prove the behavior. Do not add broad assertion grids, helper tests, implementation-detail tests, or "comfort" tests just to cover every branch.

Prefer one real failing/reproducing user workflow over many small code-level tests. If existing user workflow diagnostics already cover the behavior, update or reuse them instead of adding PHPUnit/Pest tests.

Use PHPUnit/Pest only when the user asks for it, when an existing test must be updated because behavior changed, or when a low-level invariant cannot practically be proven through user interaction.

For HTTP 400, 401, 403, 404, 419, 422, or 500 errors:

1. Reproduce the exact failing browser request first.
2. Inspect the request URL, HTTP method, headers, query parameters, and payload.
3. Inspect the complete response status and response body.
4. Check Laravel logs and the running server output.
5. Check the Laravel route, middleware, FormRequest, controller, and frontend caller.
6. Do not modify code until the failing request has been reproduced and the root cause identified.
7. Run only one directly relevant test file or filtered test when necessary.
8. Do not rerun a successful command without a clear reason.
9. Ask before running commands expected to take longer than 30 seconds.

When debugging frontend requests, prioritize the browser Network request over assumptions based on backend tests.

When reporting completion, include:

- Exact cause
- Exact failing request
- Exact changed files
- Exact verification performed
