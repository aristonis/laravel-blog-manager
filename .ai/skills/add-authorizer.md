# Skill — add an authorization driver

Goal: back blog ability checks with your own scheme (spatie/laravel-permission, custom logic) **without editing
the core** (OCP). The package defines ability keys and delegates the decision — it never models roles/permissions.

## Ability keys
`Aristonis\BlogManager\Authorization\Abilities`: `blog.post.create|update|delete`, `blog.block.manage`,
`blog.media.upload|delete`.

## Steps
1. **Implement** `Aristonis\BlogManager\Contracts\Authorizer`:
   ```php
   public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool;
   public function authorize(?Authenticatable $user, string $ability, mixed $subject = null): void; // throw AuthorizationDeniedException
   ```

2. **Register** the driver from your app's provider `boot()`:
   ```php
   use Aristonis\BlogManager\Authorization\AuthorizationManager;

   $this->app->make(AuthorizationManager::class)
       ->extend('permission', fn () => new SpatiePermissionAuthorizer);
   ```

3. **Select + enforce** via config:
   - `authorization.driver` = `none` (default, allow-all) | `gate` | your driver key.
   - `authorization.enforce_in_services` = `true` to also guard the service layer (default: API edge only).

## Built-in drivers
- `none` — allow everything (default).
- `gate` — delegate to Laravel Gate/policies (undefined abilities are denied; register policies to grant).

## Rules
- Define abilities, never roles/permissions — that stays in the host / spatie.
- A denied ability throws `AuthorizationDeniedException` (4001); an unknown driver throws
  `AuthorizationDriverNotFoundException` (4002).
