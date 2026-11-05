export type TicketStats = {
    by_status: Record<string, number>;
    status_labels: Record<string, string>;
    open: number;
    overdue: number;
    created_today: number;
    closed_today: number;
    sla_percent: number | null;
    avg_resolution_hours: number | null;
    trend: { date: string; created: number; closed: number }[];
    by_vendor: { vendor: string; open: number }[];
    workload: { technician: string; active: number }[];
};

export type AssetStats = {
    by_status: { status: string; label: string; total: number }[];
    stock: { model: string; quantity: number }[];
};

export type IntegrationStats = { waiting: number; dead: number };

export type ReportFilters = {
    from: string;
    to: string;
    vendor: string;
    status: string;
    type: string;
    q: string;
};

export type ReportRow = { id: string; cells: string[] };
