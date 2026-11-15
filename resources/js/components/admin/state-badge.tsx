import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { SubscriptionState } from '@/types';

const STYLES: Record<SubscriptionState, string> = {
    trial: 'border-sky-200 bg-sky-50 text-sky-800',
    active: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    grace: 'border-amber-200 bg-amber-50 text-amber-800',
    expired: 'border-red-200 bg-red-50 text-red-800',
};

export function StateBadge({ state, label }: { state: SubscriptionState; label: string }) {
    return (
        <Badge variant="outline" className={cn('font-normal', STYLES[state])}>
            {label}
        </Badge>
    );
}
