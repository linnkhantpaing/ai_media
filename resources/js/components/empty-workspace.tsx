import { useTranslations } from '@/lib/i18n';

export function EmptyWorkspace() {
    const { t } = useTranslations();
    return (
        <section className="projects-panel" aria-labelledby="projects-title">
            <div className="panel-heading">
                <h2 id="projects-title">{t('yourProjects')}</h2>
                <span className="panel-label">{t('freshCanvas')}</span>
            </div>

            <div className="empty-workspace">
                <div className="frame-art" aria-hidden="true">
                    <div className="frame-art-glow" />
                    <div className="art-frame art-frame-back" />
                    <div className="art-frame art-frame-middle" />
                    <div className="art-frame art-frame-front">
                        <div className="art-frame-dots"><i /><i /><i /></div>
                        <div className="art-frame-scene">
                            <svg viewBox="0 0 40 40" fill="none">
                                <path d="m16 11 14 9-14 9V11Z" fill="currentColor" />
                            </svg>
                        </div>
                        <div className="art-frame-track"><i /><i /><i /></div>
                    </div>
                    <svg className="art-spark" viewBox="0 0 24 24" fill="none">
                        <path d="M12 1c0 7-4 11-11 11 7 0 11 4 11 11 0-7 4-11 11-11C16 12 12 8 12 1Z" fill="currentColor" />
                    </svg>
                </div>
                <h3>{t('nextStory')}</h3>
                <p>{t('spaceForIdeas')}<br />{t('projectsHome')}</p>
            </div>
        </section>
    );
}
