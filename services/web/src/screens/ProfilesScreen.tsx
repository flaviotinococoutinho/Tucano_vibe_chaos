import type { ReactElement } from 'react';
import { ActionForm, ProfileCard } from '../components/index.ts';
import { entitiesOf, findAction, findLink, readString, type SirenScreen } from '../siren/index.ts';
import './ProfilesScreen.css';

/** Who is shopping: the profiles of this browser, and the form that makes a new one. */
export function ProfilesScreen({ screen }: { readonly screen: SirenScreen }): ReactElement {
  const intro = readString(screen.properties, 'intro');
  const profiles = entitiesOf(screen, 'item');
  const createProfile = findAction(screen.actions, 'create-profile');

  return (
    <div className="profiles-screen">
      {intro !== undefined ? <p className="profiles-screen__intro">{intro}</p> : null}
      {profiles.length > 0 ? (
        <ul className="profiles-screen__list">
          {profiles.map((entity, index) => (
            <ProfileCard
              entity={entity}
              key={
                readString(entity.properties, 'profileId') ??
                findLink(entity.links, 'self')?.href ??
                index
              }
            />
          ))}
        </ul>
      ) : null}
      {createProfile !== undefined ? (
        <section className="profiles-screen__create" aria-labelledby="profiles-create-heading">
          <h2 id="profiles-create-heading">Novo perfil</h2>
          <ActionForm action={createProfile} />
        </section>
      ) : null}
    </div>
  );
}
