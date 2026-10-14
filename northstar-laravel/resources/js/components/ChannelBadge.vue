<template>
  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1 text-[11px] font-semibold" :class="styles[platform ?? name] ?? 'bg-muted text-muted-foreground'">
    <span class="size-1.5 rounded-full bg-current" />{{ name ?? 'Manual' }}
  </span>
</template>

<script setup>
/** name: the store's label; platform: Shopify/Amazon/Etsy/eBay, which picks the colour. */
defineProps({ name: String, platform: String });

const styles = {
  Shopify: 'bg-[#e7f5eb] text-[#32804c] dark:bg-[#32804c]/20 dark:text-[#8fd3a5]',
  Amazon: 'bg-[#fff2d9] text-[#a96600] dark:bg-[#a96600]/20 dark:text-[#f5c46b]',
  Etsy: 'bg-[#ffebe5] text-[#c45032] dark:bg-[#c45032]/20 dark:text-[#f59a82]',
  eBay: 'bg-[#e9f0ff] text-[#3e72d2] dark:bg-[#3e72d2]/20 dark:text-[#9bb9f2]',
};
</script>
