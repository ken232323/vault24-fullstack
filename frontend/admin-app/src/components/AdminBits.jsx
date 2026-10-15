import { Badge } from '@mantine/core';

const outcomeColor = { success: 'green', failure: 'red', denied: 'orange', blocked: 'red' };
const severityColor = { low: 'gray', medium: 'yellow', high: 'red' };

export const OutcomeBadge = ({ outcome }) => <Badge variant="light" color={outcomeColor[outcome] || 'gray'}>{outcome}</Badge>;
export const SeverityBadge = ({ severity }) => <Badge color={severityColor[severity] || 'gray'}>{severity}</Badge>;
