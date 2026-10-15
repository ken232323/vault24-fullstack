import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().trim().email('Enter a valid email address'),
  password: z.string().min(1, 'Enter your password'),
});

export const staffSchema = z.object({
  name: z.string().trim().min(2, 'Enter the full name').max(80, 'Name is too long'),
  email: z.string().trim().email('Enter a valid email address'),
  password: z
    .string()
    .min(12, 'Use at least 12 characters')
    .regex(/[a-z]/, 'Add a lowercase letter')
    .regex(/[A-Z]/, 'Add an uppercase letter')
    .regex(/\d/, 'Add a number'),
  role: z.enum(['staff', 'admin'], { errorMap: () => ({ message: 'Choose a role' }) }),
  currentPassword: z.string().min(1, 'Enter your own password to confirm'),
});
