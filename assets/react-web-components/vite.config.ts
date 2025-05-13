import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const isProduction = mode === 'production';

  return {
    plugins: [
      ...(isProduction ? [react()] : []), // 本番ビルドのみ React プラグインを使う
    ],
    define: {
      'process.env.NODE_ENV': JSON.stringify(mode),
    },
    server: {
      host: 'localhost',
      port: 5173,
      cors: true,
      strictPort: true,
    },
    build: {
      lib: {
        entry: 'src/main.ts',
        formats: ['es'],
        fileName: 'web-components',
      },
      rollupOptions: {
        external: ['react', 'react-dom'],
        output: {
          globals: {
            react: 'React',
            'react-dom': 'ReactDOM',
          },
          paths: {
            react: 'https://esm.sh/react@18.2.0',
            'react-dom': 'https://esm.sh/react-dom@18.2.0',
          },
        },
      },
    },
  };
});
