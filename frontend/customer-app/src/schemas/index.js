import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().trim().email('Enter a valid email address'),
  password: z.string().min(1, 'Enter your password'),
});

export const registerSchema = z
  .object({
    name: z.string().trim().min(2, 'Enter your full name').max(80, 'Name is too long'),
    email: z.string().trim().email('Enter a valid email address'),
    password: z
      .string()
      .min(12, 'Use at least 12 characters')
      .regex(/[a-z]/, 'Add a lowercase letter')
      .regex(/[A-Z]/, 'Add an uppercase letter')
      .regex(/\d/, 'Add a number'),
    confirmPassword: z.string(),
  })
  .refine((d) => d.password === d.confirmPassword, { path: ['confirmPassword'], message: 'Passwords do not match' });

export const transferSchema = z.object({
  toAccountNumber: z.string().trim().regex(/^\d{10}$/, 'Account numbers have 10 digits'),
  amount: z
    .string()
    .trim()
    .regex(/^\d+(\.\d{1,2})?$/, 'Enter an amount like 25 or 25.50')
    .refine((v) => parseFloat(v) > 0, 'Amount must be more than K0.00'),
  note: z.string().max(60, 'Keep the note under 60 characters'),
});

export const profileSchema = z.object({
  name: z.string().trim().min(2, 'Enter your full name').max(80, 'Name is too long'),
});
