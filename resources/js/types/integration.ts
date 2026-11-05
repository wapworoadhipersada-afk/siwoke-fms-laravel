export type IntegrationClientRow = {
    ulid: string;
    name: string;
    vendor: string;
    key_id: string;
    is_active: boolean;
    has_webhook: boolean;
    webhook_url: string | null;
    allowed_ips: string[];
    last_used_at: string | null;
};

export type IntegrationLogRow = {
    id: number;
    direction: 'in' | 'out';
    method: string;
    path: string;
    status_code: number | null;
    duration_ms: number | null;
    correlation_id: string;
    error: string | null;
    request_body: string | null;
    response_body: string | null;
    created_at: string | null;
};

export type OutboundSummary = {
    waiting: number;
    stuck: number;
    dead: {
        ulid: string;
        client: string;
        attempts: number;
        last_error: string | null;
        created_at: string | null;
    }[];
};
