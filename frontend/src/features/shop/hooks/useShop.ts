import { useQuery } from '@tanstack/react-query';
import { shopApi } from '@/features/shop/api/shopApi';

export const shopKeys = {
    all: ['shop'] as const,
};

export function useShop() {
    return useQuery({ queryKey: shopKeys.all, queryFn: shopApi.get });
}
