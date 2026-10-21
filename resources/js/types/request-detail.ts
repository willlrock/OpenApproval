export interface RequestPerson {
    id: number;
    full_name: string;
    username: string | null;
    title: string | null;
    phone: string | null;
}

export interface RequestApproval {
    id: number;
    level: number;
    status: string;
    comment: string | null;
    action_date: string | null;
    approver: RequestPerson | null;
}

export interface RequestTranche {
    id: number;
    amount: number;
    percent: number | null;
    status: 'pending' | 'paid' | 'failed';
    due_date: string | null;
    promised_date: string | null;
    paid_at: string | null;
    comment: string | null;
    failure_reason: string | null;
    payment_proof: string | null;
    payment_proof_url: string | null;
    can_manage: boolean;
    deadline_history: {
        previous_date: string | null;
        promised_date: string | null;
        reason: string;
        created_at: string;
        user_id: number | null;
        user_name: string | null;
    }[];
}

export interface RequestDetailData {
    id: number;
    revision: number;
    type: string;
    name: string;
    description: string;
    status: string;
    currency: string | null;
    total_price: number | null;
    quantity: number | null;
    price_per_unit: number | null;
    transaction_type: string | null;
    supplier_name: string | null;
    supplier_inn: string | null;
    supplier_id: number | null;
    supplier: { id: number; name: string; inn: string | null } | null;
    document_kind: string | null;
    document_number: string | null;
    effective_date_label: string | null;
    pdf_url: string | null;
    document_preview_url: string | null;
    deadline_date: string | null;
    deadline_date_iso: string | null;
    deadline_from_supplier: string | null;
    factual_delivery_date: string | null;
    created_at: string;
    updated_at: string;
    expected_approval_level: number | null;
    current_approval_id: number | null;
    source_id: number | null;
    can_approve: boolean;
    can_request_changes: boolean;
    can_delete: boolean;
    can_copy: boolean;
    can_revise: boolean;
    can_recover_route: boolean;
    route_unavailable: { id: number; name: string }[];
    can_manage_supply: boolean;
    can_manage_payments: boolean;
    can_attach_supplier: boolean;
    project: { id: number; name: string } | null;
    applicant: RequestPerson | null;
    supply_applicant: RequestPerson | null;
    approvals: RequestApproval[];
    attachments: {
        id: number;
        name: string;
        file_size: number | null;
        file_type: string;
        telegram_file_id: string | null;
        url: string;
    }[];
    items: {
        id: number;
        name: string;
        description: string | null;
        quantity: number;
        price_per_unit: number | null;
        total_price: number | null;
    }[];
    tranches: RequestTranche[];
}
