<template>
  <p class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-slate-500">
    <RouterLink :to="{ name: 'privacy' }" class="hover:text-slate-700 hover:underline">Privacy policy</RouterLink>
    <RouterLink :to="{ name: 'cookie' }" class="hover:text-slate-700 hover:underline">Cookie policy</RouterLink>
  </p>
</template>
