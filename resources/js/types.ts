export type SharedProps = {
    auth: { admin: { name: string; phone: string } | null };
    flash: { status: string | null };
    links: { webApp: string | null; supportEmail: string | null; supportPhone: string | null; playStore: string | null; appStore: string | null };
    errors: Record<string, string>;
};

export type Business = { name: string; address: string | null; email: string | null; phone: string | null; jurisdiction?: string | null };

export type ActivityRow = {
    id: number;
    when: string;
    who: string;
    library: { id: number; name: string } | null;
    text: string;
    note: string | null;
};

export type SubscriptionState = 'trial' | 'active' | 'grace' | 'expired';
