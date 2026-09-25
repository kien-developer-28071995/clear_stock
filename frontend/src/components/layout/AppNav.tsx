import { NavMenu } from '@shopify/app-bridge-react';

/** Sidebar navigation in the Shopify admin (rendered outside the iframe by App Bridge). */
export function AppNav() {
    return (
        <NavMenu>
            <a href="/" rel="home">
                Home
            </a>
        </NavMenu>
    );
}
