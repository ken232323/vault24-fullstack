import { Alert } from '@mantine/core';
import { IconAlertCircle } from '@tabler/icons-react';

// Shows the server's safe message plus the request ID so support can trace it.
export default function ErrorAlert({ error, title = 'Something went wrong' }) {
  if (!error) return null;
  return (
    <Alert color="red" icon={<IconAlertCircle size={18} />} title={title} role="alert">
      {error.message}
      {error.requestId && <div style={{ fontSize: 12, opacity: 0.7, marginTop: 4 }}>Reference: {error.requestId}</div>}
    </Alert>
  );
}
