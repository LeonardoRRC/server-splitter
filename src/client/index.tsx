import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('split', () => import('./screens/SplitScreen'));
        screens.register('settings', () => import('./screens/SettingsScreen'));
    },
});
