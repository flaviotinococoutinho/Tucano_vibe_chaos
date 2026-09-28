import type { ReactElement } from 'react';
import { Header } from './components/index.ts';
import { HypermediaProvider } from './hypermedia/index.ts';
import { ScreenRouter } from './screens/index.ts';

export function App(): ReactElement {
  return (
    <HypermediaProvider>
      <a href="#main-content" className="skip-link">
        Pular para o conteúdo
      </a>
      <Header />
      <ScreenRouter />
    </HypermediaProvider>
  );
}
