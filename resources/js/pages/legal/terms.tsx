import { Link } from '@inertiajs/react';

import { Fill, LegalLayout } from '@/layouts/legal-layout';
import type { Business } from '@/types';

export default function Terms({ updated, business, trialDays }: { updated: string; business: Business; trialDays: number }) {
    return (
        <LegalLayout title="Terms and Conditions" updated={updated}>
            <p>
                These terms are an agreement between you and <Fill value={business.name} label="Business name" /> (“we”, “us”), the maker of LibriSpace. By creating an account or using
                LibriSpace (the app, the web app and this website), you agree to them. If you use LibriSpace for a business, you accept them on its behalf.
            </p>

            <h2>1. The service</h2>
            <p>
                LibriSpace helps owners of study libraries and reading rooms manage seats, batches, admissions, fees, renewals, attendance and reports, and send reminders to their
                students. We may improve, change or add features over time.
            </p>

            <h2>2. Your account</h2>
            <ul>
                <li>You sign up with an Indian mobile number that you own, verified by a one-time code. You must be at least 18.</li>
                <li>Keep your password private. You are responsible for what happens in your account; tell us at once if you think someone else has used it.</li>
                <li>Give accurate details about yourself and your library.</li>
            </ul>

            <h2>3. Free trial and plans</h2>
            <ul>
                <li>New libraries get a free trial of {trialDays} days (the length shown when you sign up) with every feature.</li>
                <li>After the trial, you buy a plan for a fixed period (for example one, three or twelve months) at the price shown in the app at the time of purchase. Plans are paid in advance and do not renew automatically.</li>
                <li>Payments are processed by Razorpay. Prices are in Indian rupees; applicable taxes are shown before you pay.</li>
                <li>If a trial or plan ends and you don’t buy a plan, you keep full access for a short grace period, after which your library becomes read-only. Your data is not deleted, and everything unlocks when you subscribe.</li>
                <li>We may change plan prices for future purchases. A plan you have already paid for keeps its price until it ends.</li>
                <li>Refunds are covered by our <Link href="/refunds">Cancellation and Refund Policy</Link>.</li>
            </ul>

            <h2>4. Your data and your students’ data</h2>
            <ul>
                <li>The data you enter about your library and your students belongs to you. You give us permission to store and process it only to provide LibriSpace to you, as described in our <Link href="/privacy">Privacy Policy</Link>.</li>
                <li>You are responsible for having your students’ consent (and a guardian’s consent for those under 18) to record their details and ID proofs and to send them messages, and for keeping that data accurate.</li>
                <li>Only send reminders to students of your own library, about their library membership. Don’t use LibriSpace for promotional messages or spam.</li>
            </ul>

            <h2>5. Acceptable use</h2>
            <p>You agree not to:</p>
            <ul>
                <li>break any law, or upload data you have no right to use;</li>
                <li>try to access another library’s data, our servers or our code in ways we haven’t offered, or disrupt the service;</li>
                <li>copy, resell or rebrand LibriSpace, or use it to build a competing product.</li>
            </ul>
            <p>We may suspend an account that breaks these rules, and will tell you why unless the law prevents it.</p>

            <h2>6. Availability and support</h2>
            <p>
                We work to keep LibriSpace available and your data safe, and we take regular backups, but we can’t promise it will always be uninterrupted or error-free. SMS, WhatsApp
                and push delivery depend on third-party networks and may sometimes be delayed or fail.
            </p>

            <h2>7. Our liability</h2>
            <p>
                LibriSpace is a record-keeping tool; decisions about fees, admissions and your students remain yours. To the extent the law allows, we are not liable for indirect
                losses such as lost profits or business, and our total liability for any claim is limited to the amount you paid us in the 12 months before the claim. Nothing here
                limits rights you have under consumer protection law that cannot be limited.
            </p>

            <h2>8. Ending the agreement</h2>
            <p>
                You can stop using LibriSpace at any time and ask us to delete your account (see the Privacy Policy). We may end these terms if you seriously break them. Sections 4, 7 and
                9 continue after the agreement ends.
            </p>

            <h2>9. Law and disputes</h2>
            <p>
                These terms are governed by the laws of India. We will first try to resolve any dispute with you in good faith; if that fails, the courts at{' '}
                <Fill value={business.jurisdiction} label="City for jurisdiction" /> have jurisdiction.
            </p>

            <h2>10. Changes and contact</h2>
            <p>
                We may update these terms; if a change matters, we will tell you in the app or by SMS before it applies. Questions? Email{' '}
                <a href={`mailto:${business.email ?? ''}`}><Fill value={business.email} label="Support email" /></a> or see our <Link href="/contact">contact page</Link>.
            </p>
        </LegalLayout>
    );
}
