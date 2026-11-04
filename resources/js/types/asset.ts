export type ModelRow = {
    ulid: string;
    name: string;
    category: string;
    tracking: string;
    assets_count: number;
    is_active: boolean;
};

export type CategoryOption = {
    value: string;
    label: string;
    tracking: string;
};

export type WarehouseRow = {
    ulid: string;
    code: string;
    name: string;
    location: string;
    pic: string | null;
    is_active: boolean;
};

export type WarehouseForm = {
    ulid: string;
    location: string;
    code: string;
    name: string;
    pic: string | null;
    is_active: boolean;
};

export type AssetRow = {
    ulid: string;
    serial_number: string;
    asset_tag: string | null;
    model: string;
    vendor: string;
    status: string;
    status_label: string;
    condition_label: string;
    holder: string;
};

export type AssetDetail = {
    ulid: string;
    serial_number: string;
    asset_tag: string | null;
    model: string;
    category: string;
    vendor: string;
    status: string;
    status_label: string;
    condition: string;
    condition_label: string;
    holder: string;
    pending_handover: string | null;
};

export type HistoryEntry = {
    movement_no: string;
    type_label: string;
    from: string;
    to: string;
    actor: string;
    occurred_at: string;
    notes: string | null;
};

export type ModelOption = {
    ulid: string;
    label: string;
    tracking: string;
};

export type StockRow = {
    id: number;
    model: string;
    vendor: string;
    holder_type: string;
    holder: string;
    quantity: number;
};

export type HandoverRow = {
    ulid: string;
    type_label: string;
    status: string;
    status_label: string;
    from: string;
    to: string;
    requested_by: string;
    requested_at: string;
    resolution_note: string | null;
    items: string[];
    can_resolve: boolean;
    can_cancel: boolean;
};

export type StockLine = {
    model: string;
    vendor: string;
    label: string;
    available: number;
};

export type PickableAsset = {
    ulid: string;
    label: string;
    vendor: string;
};
