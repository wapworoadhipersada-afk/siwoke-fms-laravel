export type RoleRow = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    permissions_count: number;
    users_count: number;
    is_system: boolean;
    locked: boolean;
};

export type PermissionGroup = {
    key: string;
    label: string;
    permissions: { name: string; label: string; privileged: boolean }[];
};

export type RoleDetail = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    is_system: boolean;
    locked: boolean;
    permissions: string[];
};

export type RoleOption = { name: string; label: string; privileged: boolean };

export type UserRow = {
    ulid: string;
    name: string;
    email: string;
    employee_no: string | null;
    user_code: string | null;
    photo_url: string | null;
    user_type_label: string;
    phone: string | null;
    employment_label: string | null;
    roles: string[];
    is_active: boolean;
    is_self: boolean;
};

export type ManagedUser = {
    ulid: string;
    name: string;
    email: string;
    phone: string | null;
    employee_no: string | null;
    user_code: string | null;
    photo_url: string | null;
    photo_full_url: string | null;
    user_type: string;
    is_active: boolean;
    roles: string[];
    is_self: boolean;
};

export type MenuSetting = {
    id: number;
    key: string;
    parent_id: number | null;
    section: string;
    label: string;
    permission: string | null;
    is_active: boolean;
    visible: boolean;
    sort_order: number;
};

export type MenuEntry = {
    id: number;
    key: string;
    parent_id: number | null;
    section: string;
    label: string;
    icon: string | null;
    route_name: string | null;
    url: string | null;
    is_group: boolean;
    is_custom: boolean;
    permission_name: string | null;
    is_active: boolean;
    sort_order: number;
};

export type AuditEntry = { id: number; event: string; actor: string; at: string | null };
