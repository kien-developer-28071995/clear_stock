import { AppProviders } from '@/app/providers';
import { AppRouter } from '@/app/router';
import { AppNav } from '@/components/layout/AppNav';
import { LanguageSync } from '@/features/shop/components/LanguageSync';

export function App() {
    return (
        <AppProviders>
            <LanguageSync />
            <AppNav />
            <AppRouter />
        </AppProviders>
    );
}
