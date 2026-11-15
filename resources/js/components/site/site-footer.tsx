import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types';

export function SiteFooter() {
    const { links } = usePage<SharedProps>().props;

    return (
        <footer className="bg-night text-[#d9cfc3]">
            <div className="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
                <div className="space-y-4">
                    <img src="/brand/librispace-white.svg" alt="LibriSpace" className="h-7 w-auto" />
                    <p className="max-w-sm text-sm leading-relaxed text-[#a99d90]">
                        The app for study libraries and reading rooms: seats, batches, admissions, fees and attendance in one place.
                    </p>
                    <div className="flex items-center gap-2 pt-2 text-sm text-[#a99d90]">
                        <span>A product of</span>
                        <img src="/brand/kailashray-white.svg" alt="KailashRay" className="h-5 w-auto" />
                    </div>
                </div>
                <div>
                    <h3 className="mb-3 text-xs font-medium tracking-[0.14em] text-[#8c8178] uppercase">Product</h3>
                    <ul className="space-y-2 text-sm">
                        <li>
                            <a href="/#features" className="hover:text-white">Features</a>
                        </li>
                        <li>
                            <a href="/#pricing" className="hover:text-white">Pricing</a>
                        </li>
                        <li>
                            <a href="/#faq" className="hover:text-white">FAQ</a>
                        </li>
                        {links.webApp && (
                            <li>
                                <a href={`${links.webApp}/login`} className="hover:text-white">Sign in</a>
                            </li>
                        )}
                    </ul>
                </div>
                <div>
                    <h3 className="mb-3 text-xs font-medium tracking-[0.14em] text-[#8c8178] uppercase">Company</h3>
                    <ul className="space-y-2 text-sm">
                        <li>
                            <Link href="/contact" className="hover:text-white">Contact us</Link>
                        </li>
                        <li>
                            <Link href="/terms" className="hover:text-white">Terms and Conditions</Link>
                        </li>
                        <li>
                            <Link href="/privacy" className="hover:text-white">Privacy Policy</Link>
                        </li>
                        <li>
                            <Link href="/refunds" className="hover:text-white">Cancellation and Refunds</Link>
                        </li>
                    </ul>
                </div>
            </div>
            <div className="border-t border-white/10">
                <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-5 text-xs text-[#8c8178] sm:flex-row sm:justify-between sm:px-6">
                    <span>© {new Date().getFullYear()} KailashRay. All rights reserved.</span>
                    <span>Made in India</span>
                </div>
            </div>
        </footer>
    );
}
