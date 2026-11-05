# Role-based navigation and access management

This feature extends the existing IAM module and Spatie Permission installation. It does not replace Spatie's roles, permissions, model_has_roles, or role_has_permissions tables.

## Design decisions

- Spatie Permission remains the source of truth for roles and permissions.
- Navigation is stored in navigation_menus; nested items use parent_id.
- navigation_menu_role stores per-role visibility and ordering.
- A menu's optional permission_name is an additional visibility filter and must reference a Spatie permission.
- Hiding a menu is not authorization. Every route and action must still enforce a Gate, Policy, or can middleware.
- The existing Super Admin Gate::before behavior remains unchanged.
- navigation_access_audits records before/after snapshots for role navigation changes.
- Preview is a read-only rendering operation; it must never write pivot rows.

## Rollout plan

1. Deploy and run migrations.
2. Seed navigation_menus from the current sidebar definitions and map each item to its existing permission constant. Review parity before switching the frontend.
3. Add an IAM-authorized controller for menu CRUD, role-menu assignment, preview, and audit listing. Protect mutations with the existing iam.role.manage permission.
4. Add the menu tree to Inertia shared props and render it in Vue. Keep the static sidebar as fallback until parity is verified.
5. Keep all current controller/API authorization checks. Add tests for role filtering, nested filtering, ordering, preview non-persistence, audit snapshots, and denied mutations.
6. Provide an explicit admin-only catalogue sync command; never overwrite administrator ordering or visibility on every deployment.

## Migration safety

Menus without any role assignment are treated as shared navigation (still filtered by permission) during staged rollout. Once all menu definitions have been mapped, consider switching to fail-closed role assignments.
