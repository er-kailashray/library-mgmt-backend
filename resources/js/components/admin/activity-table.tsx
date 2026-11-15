import { Link } from '@inertiajs/react';

import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ActivityRow } from '@/types';

/** One line per admin change, in plain words. */
export function ActivityTable({ rows, showLibrary }: { rows: ActivityRow[]; showLibrary: boolean }) {
    if (rows.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">No changes yet.</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Who</TableHead>
                    {showLibrary && <TableHead>Library</TableHead>}
                    <TableHead>What changed</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <TableCell className="whitespace-nowrap text-muted-foreground">{row.when}</TableCell>
                        <TableCell>{row.who}</TableCell>
                        {showLibrary && (
                            <TableCell>
                                {row.library ? (
                                    <Link href={`/admin/libraries/${row.library.id}`} className="text-brand hover:underline">
                                        {row.library.name}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">—</span>
                                )}
                            </TableCell>
                        )}
                        <TableCell className="whitespace-normal">
                            {row.text}
                            {row.note && <div className="text-sm text-muted-foreground">“{row.note}”</div>}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
