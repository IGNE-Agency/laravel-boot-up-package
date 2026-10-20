Laravel specific. Ignore when not a laravel project:

- Do not duplicate business rules across controllers, requests, actions, jobs, listeners, policies, resources, or views.
- Do not use facades inside domain services when dependency injection is cleaner, unless the facade is the clear Laravel standard choice.
- Do not use named arguments when calling Laravel framework methods.
- Follow Laravel conventions for directory structure, naming, validation, authorization, queues, events, notifications, resources, casts, and tests.

Always do:

- Avoid magic, hidden side effects, clever shortcuts, and speculative abstractions.
- Keep code SOLID, DRY, cohesive, and easy to refactor.
- Prefer composition over inheritance.
- Prefer small classes with one clear responsibility.
- Use constructor dependency injection.

When generating code:

- First think through the architecture briefly.
- Then output the final code only, unless explanation is requested.
- If multiple files are needed, provide them in this format:

=== File: app/Services/Billing/InvoiceService.php ===

```php
<?php
declare(strict_types=1);

// code...

Standards:

Controllers must stay thin.
Move business logic into dedicated services, actions, or domain classes.
Use Form Requests for validation.
Use Policies / Gates for authorization.
Use Eloquent scopes, casts, value objects, and query objects where they improve clarity.
Avoid fat models and avoid anemic "god services".
Use API Resources for API responses.
Use DTOs / data objects when they clarify boundaries between layers.
Prefer enums for fixed state.
Prefer explicit exceptions and proper failure paths over silent fallbacks.
Validate all external input.
Escape output appropriately in Blade.
Keep database queries efficient and prevent N+1 issues.
Use transactions where consistency matters.
Queue slow work.
Write idempotent jobs where possible.
Prefer immutable data patterns where practical.
Do not introduce repositories unless they solve a real problem.
Do not create interfaces for every class without a real need.
Do not over-engineer.

Database and Eloquent:

Use proper migrations with indexes, foreign keys, uniqueness constraints, and sensible nullability.
Be explicit about cascading behavior.
Use typed custom casts or value objects when useful.
Keep model attributes, relationships, scopes, and casts clean and minimal.
Prefer Model::query() for readability in non-trivial queries.
Eager load intentionally.
Avoid raw SQL unless necessary.

HTTP and API:

Use route model binding when appropriate.
Return correct HTTP status codes.
Keep request / response contracts explicit.
For APIs, return consistent JSON shapes.
Use Resources instead of returning models directly.
Do not leak internal fields.

Testing:

Write Pest or PHPUnit tests compatible with Laravel 12.
Cover happy path, validation failures, authorization, and important edge cases.
Prefer feature tests for user-visible behavior and unit tests for isolated domain logic.
Use factories cleanly.
Keep tests readable and deterministic.

Frontend / Blade / Inertia:

If Blade is used, keep templates simple and push logic out of views.
If Inertia / TypeScript is used, keep backend contracts explicit and stable.
Do not invent frontend structure unless requested.

Output quality bar:

No placeholders.
No pseudocode.
No TODO comments unless explicitly requested.
No dead code.
No duplicate logic.
No unused imports.
No vague naming.
No unsafe shortcuts.

If requirements are ambiguous:

Make the smallest reasonable assumption.
Choose the simplest maintainable implementation.
Preserve extensibility without overbuilding.

Before finalizing, self-check:

Is every PHP file strict typed?
Are all signatures typed?
Is validation in the right place?
Is authorization in the right place?
Is business logic outside the controller?
Is duplication removed?
Is the code idiomatic Laravel 12?
Is the solution simple, solid, and production-safe?

```
