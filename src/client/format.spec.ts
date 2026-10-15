import { expect, test } from 'vitest';
import { fieldErrors } from './errors';
import { formatCpu, formatMiB, groupByCategory, initialEnvironment } from './format';

test('formats limits as the Panel does', () => {
    expect(formatMiB(512)).toBe('512 MiB');
    expect(formatMiB(1536)).toBe('1.5 GiB');
    expect(formatMiB(0)).toBe('Unlimited');
    expect(formatCpu(75)).toBe('75%');
    expect(formatCpu(0)).toBe('Unlimited');
});

test('starts a type form from the server values, then the defaults', () => {
    const variables = [
        { env: 'MINECRAFT_VERSION', default: 'latest' },
        { env: 'BUILD_NUMBER', default: 'latest' },
    ];
    expect(initialEnvironment(variables)).toEqual({ MINECRAFT_VERSION: 'latest', BUILD_NUMBER: 'latest' });
    expect(initialEnvironment(variables, { MINECRAFT_VERSION: '1.21.4' })).toEqual({ MINECRAFT_VERSION: '1.21.4', BUILD_NUMBER: 'latest' });
});

test('groups eggs by category with Other last', () => {
    const groups = groupByCategory([
        { name: 'Rust', category: 'Other' },
        { name: 'Vanilla', category: 'Minecraft' },
        { name: 'Paper', category: 'Minecraft' },
    ]);
    expect(groups.map((group) => group.category)).toEqual(['Minecraft', 'Other']);
    expect(groups[0].eggs.map((egg) => egg.name)).toEqual(['Paper', 'Vanilla']);
});

test('reads field errors in the Panel and Laravel formats', () => {
    const panel = { response: { data: { errors: [{ detail: 'Too much memory.', meta: { source_field: 'memory' } }] } } };
    const laravel = { response: { data: { errors: { disk: ['Too much disk.'] } } } };
    expect(fieldErrors(panel)).toEqual({ memory: 'Too much memory.' });
    expect(fieldErrors(laravel)).toEqual({ disk: 'Too much disk.' });
    expect(fieldErrors(new Error('offline'))).toEqual({});
});
