export type TicketRow = {
    ulid: string;
    ticket_no: string;
    vendor_ticket_no: string | null;
    type: string;
    vendor: string;
    tid: string;
    merchant: string;
    priority: string;
    priority_label: string;
    status: string;
    status_label: string;
    technician: string | null;
    sla_due_at: string;
    is_overdue: boolean;
};

export type TicketTypeOption = {
    ulid: string;
    name: string;
    sla_hours: number;
};

export type TicketDetail = {
    ulid: string;
    ticket_no: string;
    vendor_ticket_no: string | null;
    type: string;
    vendor: string;
    source: string;
    priority_label: string;
    status: string;
    status_label: string;
    description: string | null;
    scheduled_at: string | null;
    received_at: string;
    sla_due_at: string;
    closed_at: string | null;
    is_overdue: boolean;
    technician: string | null;
    created_by: string | null;
    tid: string;
    merchant: { ulid: string; name: string; phone: string | null };
    site: {
        address: string;
        service_point: string;
        pic_name: string | null;
        pic_phone: string | null;
        latitude: string | null;
        longitude: string | null;
    };
};

export type TimelineEntry = {
    event_label: string;
    to_status_label: string;
    actor: string;
    reason: string | null;
    occurred_at: string;
    on_behalf: boolean;
    outside_radius: boolean;
    distance_m: number | null;
    notes: string | null;
};

export type VisitEntry = {
    visit_no: number;
    technician: string;
    scheduled_at: string | null;
    arrived_at: string | null;
    closed_at: string | null;
    outcome_label: string;
    reason: string | null;
    notes: string | null;
    distance_m: number | null;
    reportable: boolean;
};

export type AssetLineEntry = {
    visit_no: number;
    action_label: string;
    item: string;
    quantity: number;
    notes: string | null;
    state: string;
    movement_no: string | null;
};

export type BeritaAcaraEntry = {
    ulid: string;
    label: string;
    visit_no: number;
    outcome_label: string;
    is_current: boolean;
    generated_at: string;
    reason: string | null;
    url: string;
};

export type AttachmentEntry = {
    visit_no: number;
    kind: string;
    kind_label: string;
    uploader: string;
    captured_at: string | null;
    uploaded_at: string | null;
    width: number | null;
    height: number | null;
    latitude: string | null;
    longitude: string | null;
    url: string;
    thumbnail_url: string;
};

export type AssignmentEntry = {
    technician: string;
    assigned_by: string;
    assigned_at: string;
    unassigned_at: string | null;
    unassign_reason: string | null;
};
