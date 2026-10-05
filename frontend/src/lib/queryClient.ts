import { MutationCache, QueryClient } from '@tanstack/react-query';
import { ApiError, errorMessage } from '@/lib/http';

/** A validation error (422): the form shows it at the field, so no toast on top. */
export const isValidationError = (error: unknown): boolean => error instanceof ApiError && error.status === 422;

export const queryClient = new QueryClient({
    // No save may fail silently: whatever a screen does with the error, the merchant is told.
    // Validation errors are left to the form; background writes opt out with `meta: { silent: true }`.
    mutationCache: new MutationCache({
        onError: (error, _variables, _context, mutation) => {
            if (mutation.meta?.silent || isValidationError(error)) return;
            shopify.toast.show(errorMessage(error), { isError: true });
        },
    }),
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            // Don't retry client errors (4xx); retry transient ones twice.
            retry: (failureCount, error) =>
                !(error instanceof ApiError && error.status >= 400 && error.status < 500) &&
                failureCount < 2,
        },
    },
});
