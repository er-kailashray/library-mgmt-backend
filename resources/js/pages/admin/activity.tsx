import { ActivityTable } from '@/components/admin/activity-table';
import { Pager } from '@/components/admin/pager';
import { Card, CardContent } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/admin-layout';
import type { ActivityRow } from '@/types';

type Props = { activity: { rows: ActivityRow[]; page: number; lastPage: number; prevUrl: string | null; nextUrl: string | null } };

export default function Activity({ activity }: Props) {
    return (
        <AdminLayout title="Activity">
            <p className="-mt-4 mb-6 text-muted-foreground">Everything changed in the admin panel, newest first.</p>
            <Card className="shadow-none">
                <CardContent>
                    <ActivityTable rows={activity.rows} showLibrary />
                </CardContent>
            </Card>
            <Pager page={activity.page} lastPage={activity.lastPage} prevUrl={activity.prevUrl} nextUrl={activity.nextUrl} />
        </AdminLayout>
    );
}
