import * as t from 'io-ts';
import { NetworkError, ObsResponse } from 'kefir-ajax';
import Kefir, { Observable } from 'kefir';
import { toggle } from '../util';

export const ApiLanguage = t.type({
  ID: t.union([t.number, t.null]),
  display_name: t.string,
  slug: t.string,
});

export type ApiLanguage = t.TypeOf<typeof ApiLanguage>;

export const ApiBlob = t.type({
  filename: t.string,
  code: t.string,
  language: ApiLanguage,
  ID: t.number,
  size: t.number,
  raw_url: t.string,
  edit_url: t.string,
});

export type ApiBlob = t.TypeOf<typeof ApiBlob>;

export const ApiRepo = t.type({
  ID: t.number,
  description: t.string,
  status: t.string,
  password: t.string,
  gist_id: t.string,
  gist_url: t.union([t.string, t.null]),
  sync: toggle,
  blobs: t.array(ApiBlob),
  rest_url: t.string,
  commits_url: t.string,
  html_url: t.string,
  created_at: t.string,
  updated_at: t.string,
});

export type ApiRepo = t.TypeOf<typeof ApiRepo>;

export const validationErrorsToString = (errs: t.Errors) =>
  `API response validation failed:\n\n${errs
    .map(
      err =>
        `* Invalid value ${
          err.context.length === 0
            ? typeof err.value
            : JSON.stringify(err.value)
        } supplied to ${
          err.context.length === 0
            ? 'root'
            : err.context.map(x => x.key).join('/')
        }`,
    )
    .join('\n')}`;

export class ClientError {
  public type = 'client';

  constructor(public response: ObsResponse) {}

  get message() {
    return 'Client had a problem';
  }
}

export class ServerError {
  public type = 'server';

  constructor(public response: ObsResponse) {}

  get message() {
    return 'Server had a problem';
  }
}

export class JsonError {
  public type = 'json';

  constructor(public error: TypeError) {}

  get message() {
    return this.error.message;
  }
}

export class ValidationError {
  public type = 'validation';

  constructor(public errs: t.Errors) {}

  get message() {
    return validationErrorsToString(this.errs);
  }
}

export type AjaxError =
  | NetworkError
  | ClientError
  | ServerError
  | JsonError
  | ValidationError;

export const foldResponse = <T extends any, S, F>(
  BodyType: t.Type<T>,
  success: (t: T) => S,
  failure: (err: AjaxError) => F,
) => (obs$: Observable<ObsResponse, NetworkError>): Observable<S | F, never> =>
  obs$
    .flatMap<unknown, AjaxError>(response => {
      // Can't guarantee any type of response on 500s
      if (response.status >= 500) {
        return Kefir.constantError(new ServerError(response));
      }

      if (response.status >= 400) {
        return Kefir.constantError(new ClientError(response));
      }

      return response.json().mapErrors(error => new JsonError(error));
    })
    .flatMap(body =>
      BodyType.validate(body, []).fold<Observable<S, ValidationError>>(
        errs => Kefir.constantError(new ValidationError(errs)),
        body => Kefir.constant(success(body)),
      ),
    )
    .flatMapErrors(err => Kefir.constant(failure(err)));
