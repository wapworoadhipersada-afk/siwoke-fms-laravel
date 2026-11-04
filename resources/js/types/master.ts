export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Option = {
    value: string;
    label: string;
};

export type UlidOption = {
    ulid: string;
    label: string;
};

export type VendorOption = {
    ulid: string;
    code: string;
    name: string;
};

export type Vendor = {
    ulid: string;
    code: string;
    name: string;
    is_active: boolean;
    merchants_count?: number;
    terminals_count?: number;
};

export type LocationRow = {
    ulid: string;
    type: string;
    type_label: string;
    code: string;
    name: string;
    parent: string | null;
    is_active: boolean;
};

export type LocationForm = {
    ulid: string;
    type: string;
    type_label: string;
    parent: string | null;
    code: string;
    name: string;
    address: string | null;
    latitude: string | null;
    longitude: string | null;
    is_active: boolean;
};

export type MerchantRow = {
    ulid: string;
    merchant_code: string;
    name: string;
    category: string | null;
    vendor: string;
    sites_count: number;
};

export type MerchantDetail = {
    ulid: string;
    merchant_code: string;
    name: string;
    owner_name: string | null;
    phone: string | null;
    category: string | null;
    vendor: string;
};

export type TerminalChip = {
    ulid: string;
    tid: string;
    status: string;
    status_label: string;
};

export type SiteDetail = {
    ulid: string;
    name: string | null;
    address: string;
    service_point: string;
    area: string | null;
    latitude: string | null;
    longitude: string | null;
    pic_name: string | null;
    pic_phone: string | null;
    is_active: boolean;
    terminals: TerminalChip[];
};

export type SiteForm = {
    ulid: string;
    location: string;
    name: string | null;
    address: string;
    latitude: string | null;
    longitude: string | null;
    pic_name: string | null;
    pic_phone: string | null;
    is_active: boolean;
};

export type TerminalRow = {
    ulid: string;
    tid: string;
    vendor: string;
    merchant: string;
    merchant_ulid: string;
    address: string;
    service_point: string;
    status: string;
    status_label: string;
};

export type SiteSummary = {
    ulid: string;
    merchant: string;
    merchant_ulid: string;
    vendor: string;
    address: string;
};
