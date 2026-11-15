import { Mail, MapPin, Phone } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { Fill, LegalLayout } from '@/layouts/legal-layout';
import type { Business } from '@/types';

export default function Contact({ business }: { business: Business }) {
    return (
        <LegalLayout title="Contact us">
            <p>We’re a small team and we read every message. For help with the app, billing, refunds or your data, reach us here.</p>
            <div className="grid gap-4 pt-2 sm:grid-cols-2">
                <Card className="shadow-none">
                    <CardContent className="flex gap-4">
                        <Mail className="mt-1 size-5 shrink-0 text-brand" />
                        <div>
                            <p className="font-medium text-foreground">Email</p>
                            <p className="text-muted-foreground">
                                {business.email ? <a href={`mailto:${business.email}`}>{business.email}</a> : <Fill value={null} label="Support email" />}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">Reply within 2 working days</p>
                        </div>
                    </CardContent>
                </Card>
                <Card className="shadow-none">
                    <CardContent className="flex gap-4">
                        <Phone className="mt-1 size-5 shrink-0 text-brand" />
                        <div>
                            <p className="font-medium text-foreground">Phone / WhatsApp</p>
                            <p className="text-muted-foreground">
                                {business.phone ? <a href={`tel:${business.phone.replace(/\s/g, '')}`}>{business.phone}</a> : <Fill value={null} label="Support phone" />}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">Mon–Sat, 10 am – 6 pm IST</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
            <Card className="shadow-none">
                <CardContent className="flex gap-4">
                    <MapPin className="mt-1 size-5 shrink-0 text-brand" />
                    <div>
                        <p className="font-medium text-foreground">
                            <Fill value={business.name} label="Business name" />
                        </p>
                        <p className="text-muted-foreground">
                            <Fill value={business.address} label="Registered address" />
                        </p>
                    </div>
                </CardContent>
            </Card>

            <h2>Delete your account</h2>
            <p>
                To delete your LibriSpace account and your library’s data, email us from your registered details, or include your registered mobile number, with the subject “Delete my
                account”. We confirm by a code to that number and delete everything within 30 days, except invoices we must keep by law. See the Privacy Policy for details.
            </p>

            <h2>Grievance officer</h2>
            <p>Privacy questions and complaints go to our Grievance Officer at the email above. We reply within 7 days and resolve complaints within 30 days.</p>
        </LegalLayout>
    );
}
