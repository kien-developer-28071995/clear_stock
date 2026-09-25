import { AppProviders } from '@/app/providers';
import { AppRouter } from '@/app/router';
import { AppNav } from '@/components/layout/AppNav';

export function App() {
    return (
        <AppProviders>
            <AppNav />
            <AppRouter />
        </AppProviders>
    );
}
