# Debugging and Testing Rules

Do not run the complete test suite unless explicitly requested.

Never run these commands automatically:

- php artisan test
- vendor/bin/phpunit
- composer test
- npm test
- npm run build

Do not create new tests unless explicitly requested.

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