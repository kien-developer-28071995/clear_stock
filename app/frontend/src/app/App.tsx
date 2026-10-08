import { AppProviders } from '@/app/providers';
import { AppRouter } from '@/app/router';
import { AppNav } from '@/components/layout/AppNav';
import { LanguageSync } from '@/features/shop/components/LanguageSync';
import { SupportChat } from '@/features/shop/components/SupportChat';

export function App() {
    return (
        <AppProviders>
            <LanguageSync />
            <SupportChat />
            <AppNav />
            <AppRouter />
        </AppProviders>
    );
}
