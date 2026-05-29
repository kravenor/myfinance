import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { User } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  async function fetchMe(): Promise<void> {
    try {
      const { data } = await api.get<{ data: User }>('/auth/me')
      user.value = data.data
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.post<{ data: User }>('/auth/login', { email, password })
      user.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function register(payload: {
    name: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.post<{ data: User }>('/auth/register', payload)
      user.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      user.value = null
    }
  }

  async function forgotPassword(email: string): Promise<string> {
    const { data } = await api.post<{ message: string }>('/auth/forgot-password', { email })
    return data.message
  }

  async function resetPassword(payload: {
    token: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<string> {
    const { data } = await api.post<{ message: string }>('/auth/reset-password', payload)
    return data.message
  }

  return {
    user,
    loading,
    initialized,
    isAuthenticated,
    fetchMe,
    login,
    register,
    logout,
    forgotPassword,
    resetPassword,
  }
})
