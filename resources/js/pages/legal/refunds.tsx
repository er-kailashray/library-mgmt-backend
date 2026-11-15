import { Link } from '@inertiajs/react';

import { Fill, LegalLayout } from '@/layouts/legal-layout';
import type { Business } from '@/types';

export default function Refunds({ updated, business, trialDays }: { updated: string; business: Business; trialDays: number }) {
    return (
        <LegalLayout title="Cancellation and Refund Policy" updated={updated}>
            <p>This policy covers LibriSpace plans bought from <Fill value={business.name} label="Business name" />. It is part of our <Link href="/terms">Terms and Conditions</Link>.</p>

            <h2>Try before you pay</h2>
            <p>Every new library gets a {trialDays}-day free trial with all features and no card needed, so you can decide whether LibriSpace suits you before paying anything.</p>

            <h2>Cancellation</h2>
            <ul>
                <li>Plans are paid in advance for a fixed period and do <strong>not</strong> renew automatically. There is no subscription to cancel and nothing is charged again unless you buy a new plan yourself.</li>
                <li>If you don’t want to continue, simply don’t renew. Your plan runs until its end date; your data stays and the library becomes read-only after a short grace period.</li>
            </ul>

            <h2>Refunds</h2>
            <ul>
                <li>
                    <strong>Charged twice or charged by mistake:</strong> if the same plan is paid for twice, or money is taken but the plan isn’t activated, we refund the extra amount in full.
                    Our system flags duplicate payments automatically, and you can also write to us.
                </li>
                <li>
                    <strong>Payment failed but money was debited:</strong> the amount is normally returned to your account automatically by your bank or by Razorpay within 5 to 7 working
                    days. If it isn’t, contact us with the payment reference.
                </li>
                <li>
                    <strong>Service not working:</strong> if LibriSpace is unavailable or a fault on our side stops you using it for a significant part of your plan and we can’t fix it, we
                    refund the unused part of the plan.
                </li>
                <li>Otherwise, a plan that has started is not refunded for the remaining period, since you could try every feature free first.</li>
            </ul>

            <h2>How refunds are paid</h2>
            <p>
                Approved refunds go back to the original payment method (UPI, card or bank account) through Razorpay within 5 to 7 working days of approval. We don’t charge any fee for a
                refund.
            </p>

            <h2>Delivery of the service</h2>
            <p>
                LibriSpace is a software service delivered online; nothing is shipped. A plan is activated on your account immediately after a successful payment, and you get an invoice in
                the app under Settings → Plan &amp; Billing. If a paid plan doesn’t show within 24 hours, contact us.
            </p>

            <h2>Contact</h2>
            <p>
                For a refund or any billing question, email <a href={`mailto:${business.email ?? ''}`}><Fill value={business.email} label="Support email" /></a> with your registered mobile
                number and the payment reference. We reply within 2 working days.
            </p>
        </LegalLayout>
    );
}
