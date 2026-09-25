import { Route, Routes } from 'react-router';
import { HomePage } from '@/features/shop/pages/HomePage';
import { NotFoundPage } from '@/components/layout/NotFoundPage';

export function AppRouter() {
    return (
        <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="*" element={<NotFoundPage />} />
        </Routes>
    );
}
