export type UserRole =
    | 'superadmin'
    | 'admin'
    | 'supplier'
    | 'approver'
    | 'requester'
    | 'accountant'
    | 'treasurer';

export type User = {
    id: number;
    telegram_id: number | null;
    full_name: string;
    username: string | null;
    title: string | null;
    phone: string | null;
    role: UserRole;
    account_status: 'new' | 'pending' | 'approved' | 'rejected';
    signature_path: string | null;
    signature_url: string | null;
    avatar?: string;
    email?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
