export type User = {
    id: number;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    isAdmin: boolean;
    canManageApi: boolean;
    canManageUsers: boolean;
    canManagePermissions: boolean;
    canAccessDashboard: boolean;
    canManageBlog: boolean;
    canManagePricing: boolean;
    canManagePages: boolean;
    canViewAnalytics: boolean;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
