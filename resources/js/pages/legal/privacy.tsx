import { Fill, LegalLayout } from '@/layouts/legal-layout';
import type { Business } from '@/types';

export default function Privacy({ updated, business }: { updated: string; business: Business }) {
    return (
        <LegalLayout title="Privacy Policy" updated={updated}>
            <p>
                LibriSpace is an app for owners of study libraries and reading rooms, made by <Fill value={business.name} label="Business name" /> (“we”, “us”). This policy explains what personal
                data LibriSpace handles, why, who it is shared with, and the choices you have. It follows India’s Digital Personal Data Protection Act, 2023 and the Information
                Technology Act, 2000 and its rules.
            </p>

            <h2>Two kinds of data, two roles</h2>
            <ul>
                <li>
                    <strong>Library owners’ data</strong> (your account): we decide how it is used, so for this data we are the data fiduciary.
                </li>
                <li>
                    <strong>Students’ data</strong> that an owner enters about their members: the library owner decides what to collect and why, so the owner is the data fiduciary and we
                    process it on the owner’s behalf, only to provide LibriSpace to them. Owners must tell their students what they record and get their consent (a parent’s or guardian’s
                    consent for students under 18).
                </li>
            </ul>

            <h2>What we collect</h2>
            <p>
                <strong>From library owners:</strong>
            </p>
            <ul>
                <li>Name, mobile number and password (stored only as a one-way hash), and an email address and profile photo if you add them.</li>
                <li>Library details: name, address, map location, amenities, photos, number of seats, batch timings and fees.</li>
                <li>Plan purchases: plan, amount, dates and the payment reference from our payment partner. We never see or store your card, UPI PIN or bank details.</li>
                <li>A device token for push notifications, if you allow notifications, and basic technical logs (such as IP address and error reports) to keep the service secure and working.</li>
            </ul>
            <p>
                <strong>About students, entered by the owner:</strong> name, mobile number, gender, address, joining date, ID proof type and photos of the ID proof, seat and batch,
                attendance, fees paid and dues.
            </p>
            <p>We do not use advertising trackers, and we do not sell personal data.</p>

            <h2>Why we use it</h2>
            <ul>
                <li>To run your account and your library in the app: seats, admissions, fees, renewals, attendance and reports.</li>
                <li>To verify your mobile number with one-time codes and keep your account secure.</li>
                <li>To send the messages you ask for: fee reminders to students by SMS or WhatsApp, and alerts to you by push notification.</li>
                <li>To take payment for plans and to keep invoices and tax records.</li>
                <li>To answer support requests, and to fix problems and prevent misuse.</li>
            </ul>

            <h2>Who we share it with</h2>
            <p>Only with the service providers we need to run LibriSpace, each bound to use it only for that purpose:</p>
            <ul>
                <li>Razorpay, to process plan payments.</li>
                <li>Twilio, to send verification codes and the SMS and WhatsApp messages you send.</li>
                <li>Expo (650 Industries), to deliver push notifications to your phone.</li>
                <li>Our hosting provider, which stores the data on secured servers.</li>
            </ul>
            <p>We may also disclose data when Indian law or a valid order from a court or government authority requires it.</p>

            <h2>How long we keep it</h2>
            <ul>
                <li>Your account and library data: for as long as your account is open. If your plan ends, your library becomes read-only but the data is kept so you can come back.</li>
                <li>When you ask us to delete your account, we delete your account and your library’s data, including students’ data, within 30 days, except records we must keep by law (such as invoices, kept for 8 years under tax law).</li>
                <li>Verification codes expire within minutes; sign-in sessions expire after 90 days.</li>
            </ul>

            <h2>How we protect it</h2>
            <p>
                All data travels over encrypted (HTTPS) connections. Passwords are hashed, ID proof photos are stored privately and are only available to the library owner when signed in,
                and each library can only see its own data. No system is perfectly secure, but if a breach affects you we will inform you and the Data Protection Board of India as the law requires.
            </p>

            <h2>Your rights</h2>
            <p>You can ask us to see the personal data we hold about you, correct it, or delete it, and you can withdraw consent at any time. Most details can be changed in the app itself.</p>
            <p>
                Students who want to see, correct or delete their data should ask their library first, since the library decides what it records. If you can’t reach them, write to us and
                we will help.
            </p>
            <p>
                To delete your LibriSpace account, email <a href={`mailto:${business.email ?? ''}`}><Fill value={business.email} label="Support email" /></a> from your registered details or with
                your registered mobile number, with the subject “Delete my account”.
            </p>

            <h2>Children</h2>
            <p>LibriSpace accounts are for adult library owners. Students’ data, including that of students under 18, is entered by their library, which is responsible for getting a guardian’s consent.</p>

            <h2>Grievance officer</h2>
            <p>
                For any privacy question or complaint, contact our Grievance Officer at <a href={`mailto:${business.email ?? ''}`}><Fill value={business.email} label="Support email" /></a>
                {business.address && <>, {business.address}</>}. We reply within 7 days and resolve complaints within 30 days. If you are not satisfied, you may approach the Data
                Protection Board of India.
            </p>

            <h2>Changes</h2>
            <p>If we change this policy in a way that matters, we will tell you in the app or by SMS before the change applies. The date at the top shows the latest version.</p>
        </LegalLayout>
    );
}
