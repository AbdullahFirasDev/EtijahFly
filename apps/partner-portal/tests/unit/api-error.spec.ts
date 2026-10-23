import { describe, expect, it } from 'vitest'
import { ApiError } from '~/utils/api-error'

describe('ApiError', () => {
  it('exposes code, message and first validation message per field', () => {
    const error = new ApiError(422, {
      message: 'The given data was invalid.',
      code: 'VALIDATION_FAILED',
      errors: { 'contact.email': ['The email field is required.', 'second'], 'passengers.0.first_name': ['Latin letters only'] },
    })

    expect(error.status).toBe(422)
    expect(error.code).toBe('VALIDATION_FAILED')
    expect(error.message).toBe('The given data was invalid.')
    expect(error.fieldErrors).toEqual({
      'contact.email': 'The email field is required.',
      'passengers.0.first_name': 'Latin letters only',
    })
  })

  it('merges business-rule field errors from details', () => {
    const error = new ApiError(422, {
      message: 'Passenger details are invalid.',
      code: 'INVALID_PASSENGERS',
      details: { errors: { 'passengers.1.date_of_birth': 'Date of birth does not match passenger type CHD.' } },
    })

    expect(error.fieldErrors['passengers.1.date_of_birth']).toContain('CHD')
  })

  it('falls back gracefully without a body', () => {
    const error = new ApiError(502)
    expect(error.code).toBe('UNKNOWN')
    expect(error.message).toContain('502')
    expect(error.fieldErrors).toEqual({})
  })
})
