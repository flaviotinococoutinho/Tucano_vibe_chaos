import { screen as pageScreen, render, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { OrderProgress } from '../../src/components/index.ts';
import { type Milestone, readProgress, type SirenScreen } from '../../src/siren/index.ts';
import { fixtures } from '../support/fixtures.ts';

function progressOf(screen: SirenScreen): readonly Milestone[] {
  const progress = readProgress(screen.properties);
  if (progress === undefined || progress.length === 0) {
    throw new Error(`the example "${screen.title}" has no progress`);
  }
  return progress;
}

function steps(): HTMLElement[] {
  return within(pageScreen.getByRole('list', { name: 'Andamento do pedido' })).getAllByRole(
    'listitem',
  );
}

describe('the progress of an order', () => {
  it('gives each milestone its state, and marks only the current one as the step', () => {
    const progress = progressOf(fixtures.orderShipped);
    render(<OrderProgress progress={progress} />);

    const items = steps();
    expect(items).toHaveLength(progress.length);
    items.forEach((item, index) => {
      const milestone = progress[index];
      expect(item).toHaveTextContent(milestone?.label ?? '');
      expect(item).toHaveClass(`order-progress__step--${milestone?.state}`);
      if (milestone?.state === 'current') {
        expect(item).toHaveAttribute('aria-current', 'step');
      } else {
        expect(item).not.toHaveAttribute('aria-current');
      }
    });
    expect(progress.map((milestone) => milestone.state)).toEqual([
      'done',
      'done',
      'done',
      'current',
      'upcoming',
    ]);
  });

  it('says each state in words too, since a shape only speaks to the eyes', () => {
    render(<OrderProgress progress={progressOf(fixtures.orderShipped)} />);

    const [placed, , , onTheWay, delivered] = steps();
    expect(placed).toHaveTextContent('etapa concluída');
    expect(onTheWay).toHaveTextContent('etapa atual');
    expect(delivered).toHaveTextContent('próxima etapa');
  });

  it('gives the time only of what is done', () => {
    render(<OrderProgress progress={progressOf(fixtures.orderShipped)} />);

    const [placed, , , onTheWay] = steps();
    expect(placed?.querySelector('time')).not.toBeNull();
    expect(onTheWay?.querySelector('time')).toBeNull();
  });

  it('ends a cancelled order where it stopped, with nothing after it', () => {
    const progress = progressOf(fixtures.orderCancelled);
    render(<OrderProgress progress={progress} />);

    const items = steps();
    const last = items.at(-1);
    expect(items).toHaveLength(progress.length);
    expect(last).toHaveClass('order-progress__step--stopped');
    expect(last).toHaveTextContent(progress.at(-1)?.label ?? '');
    expect(last).toHaveTextContent('o pedido parou aqui');
    expect(items.some((item) => item.hasAttribute('aria-current'))).toBe(false);
  });
});
