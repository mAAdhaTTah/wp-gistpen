// @flow
import type { Observable } from 'kefir';
import type {
    EditorPageState, EditorPageProps,
    SettingsState, SettingsProps, Route, CommitProps,
    Theme, Loopable, Run } from '../types';
import R from 'ramda';
import type { Job, Message } from '../types';

export const selectRoute = (state: { route: Route }): Route => state.route;

export const selectDemo = (state: SettingsState) => state.globals.demo;

export const selectThemes = (state: SettingsState) => ({
    order: Object.keys(state.globals.themes),
    dict: Object.entries(state.globals.themes).reduce((acc, [key, val]) => ({
        ...acc,
        [key]: {
            name: val,
            key,
            selected: key === state.prism.theme

        }
    }), ({}: { [key: string]: Theme }))
});

export const selectLineNumbers = (state: SettingsState) => state.prism['line-numbers'];

export const selectShowInvisibles = (state: SettingsState) => state.prism['show-invisibles'];

export const selectToken = (state: SettingsState) => state.gist.token;

const selectMessagesForRun = (state: SettingsState, run: Run): Loopable<string, Message> =>
    state.messages.filter((msg: Message) => msg.run_id === run.ID)
        .reduce((acc: Loopable<string, Message>, msg: Message) => {
            acc.order.push(msg.ID);
            acc.dict[msg.ID] = msg;

            return acc;
        }, { dict: {}, order: [] });

const selectRunsForJob = (state: SettingsState, job: Job): Loopable<string, Run> =>
    state.runs.filter((run: Run) => run.job === job.slug)
        .reduce((acc: Loopable<string, Run>, run: Run) => {
            acc.order.push(run.ID);
            acc.dict[run.ID] = {
                ...run,
                messages: selectMessagesForRun(state, run)
            };

            return acc;
        }, { dict: {}, order: [] });


export const selectJobs = (state: SettingsState) => ({
    order: Object.keys(state.jobs),
    dict: R.map(job => ({
        ...job,
        runs: selectRunsForJob(state, job)
    }), state.jobs)
});

export const selectLoading = (state: SettingsState) => state.ajax.running;

export const selectSettingsProps = (state: SettingsState): SettingsProps => ({
    loading: selectLoading(state),
    route: selectRoute(state),
    demo: selectDemo(state),
    themes: selectThemes(state),
    'line-numbers': selectLineNumbers(state),
    'show-invisibles': selectShowInvisibles(state),
    token: selectToken(state),
    jobs: selectJobs(state)
});

export const selectEditorProps = (state$: Observable<EditorPageState>): Observable<EditorPageProps> =>
    state$.map(({ ajax, authors, globals, repo, route, editor, commits }): EditorPageProps => ({
        ajax,
        globals,
        repo,
        route,
        editor,
        prism: {
            'line-numbers': false,
            'show-invisibles': editor.invisibles === 'on',
            theme: editor.theme
        },
        // eslint-disable-next-line
        commits: commits.instances.map(({ author, ID, committed_at, description, states }): CommitProps => ({
            ID,
            committed_at,
            description,
            states,
            author: authors.items[String(author)]
        })),
        selectedCommit: (() => {
            const commit = commits.instances.find(instance => instance.ID === commits.selected);

            if (!commit) {
                return;
            }

            const author = authors.items[String(commit.author)];

            if (!author) {
                return;
            }

            return {
                ID: commit.ID,
                committed_at: commit.committed_at,
                description: commit.description,
                states: commit.states,
                author
            };
        })()
    }));
