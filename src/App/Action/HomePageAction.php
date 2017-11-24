<?php

namespace App\Action;

use GuzzleHttp\ClientInterface;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Exception\ExceptionInterface as DbException;
use Zend\Db\TableGateway\TableGatewayInterface;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Stdlib\ArrayObject;

class HomePageAction implements ServerMiddlewareInterface
{
    private $client;
    private $table;

    public function __construct(
        ClientInterface $client,
        TableGatewayInterface $table
    ) {
        $this->client = $client;
        $this->table = $table;
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $params = new ArrayObject($request->getQueryParams(), ArrayObject::ARRAY_AS_PROPS);
        $start = microtime(true);
        $variables = [
            'owner' => 'ansible',
            'repository' => 'ansible',
            'lastPulls' => (int) $params->lastPulls ?: 1,
        ];
        if ($params->before) {
            $variables['before'] = $params->before;
        }
        $graph = $this->fetchUrl('graphql', [
            'body' => json_encode([
                'query' => file_get_contents(__DIR__ . '/query.graphql'),
                'variables' => $variables,
            ]),
        ], 'post');

        // Handle API errors
        if (isset($graph['errors'])) {
            return new JsonResponse($graph['errors']);
        }

        $repo = $this->parsePath($graph, 'data.repository.name');
        $owner = $this->parsePath($graph, 'data.repository.owner.login');
        $edges = $this->parsePath($graph, 'data.repository.pullRequests.edges.*');

        $errors = $records = [];
        foreach ($edges as $edge) {
            // dump($this->parsePath($edge, 'node'));
            $pullRequest = (object) $this->parsePath($edge, 'node');

            $createdAt = new \DateTime($pullRequest->createdAt);
            $mergedAt = new \DateTime($pullRequest->mergedAt);
            // https://platform.github.community/t/v4-api-missing-pullrequest-closedat-attribute/3401
            $closedAt = new \DateTime($pullRequest->updatedAt);

            $record = [
                'external_id' => $pullRequest->number,
                'owner' => $owner,
                'repo' => $repo,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'merged_at' => $mergedAt->format('Y-m-d H:i:s'),
                'closed_at' => $closedAt->format('Y-m-d H:i:s'),
                // 'time_to_first_comment'
                'time_before_merge' => $mergedAt->getTimestamp() - $createdAt->getTimestamp(),
                'time_before_close' => $closedAt->getTimestamp() - $createdAt->getTimestamp(),
                'comment_count' => $pullRequest->comments['totalCount'],
                'commit_count' => $pullRequest->commits['totalCount'],
                'reviews_count' => $pullRequest->reviews['totalCount'],
                'participants_count' => $pullRequest->participants['totalCount'],
                'additions' => $pullRequest->additions,
                'deletions' => $pullRequest->deletions,
                'changes_total' => $pullRequest->additions + $pullRequest->deletions,
                'state' => strtolower($pullRequest->state),
            ];
            try {
                // dump($record);
                $this->table->insert($record);
                $records[] = $record;
            } catch (DbException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return new JsonResponse([
            'nextPage' => 'http://php7dev.test/?' . http_build_query(array_merge($variables, [
                'before' => $this->parsePath($graph, 'data.repository.pullRequests.pageInfo.startCursor'),
            ])),
            'took' => microtime(true) - $start,
            'variables' => $variables,
            'records' => $records,
            'errors' => $errors,
            'graph' => $graph,
        ]);
    }

    public function restResponse(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $pulls = $this->fetchUrl('repos/ansible/ansible/pulls', [
            'query' => $request->getQueryParams(),
        ]);

        $errors = $records = [];
        foreach ($pulls as $index => $pullRequest) {

            $existingRecords = $this->table->select([
                'id' => $pullRequest->id,
            ]);
            if ($existingRecords->count() > 0) {
                $errors[] = 'Existing record ' . $pullRequest->id;
                continue;
            }

            $comments = $this->fetchUrl($pullRequest->comments_url);
            $commits = $this->fetchUrl($pullRequest->commits_url);

            $createdAt = new \DateTime($pullRequest->created_at);
            $mergedAt = new \DateTime($pullRequest->merged_at);
            $closedAt = new \DateTime($pullRequest->closed_at);

            $record = [
                'external_id' => $pullRequest->id,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'merged_at' => $mergedAt->format('Y-m-d H:i:s'),
                'closed_at' => $closedAt->format('Y-m-d H:i:s'),
                'time_before_merge' => $mergedAt->getTimestamp() - $createdAt->getTimestamp(),
                'time_before_close' => $closedAt->getTimestamp() - $createdAt->getTimestamp(),
                'requested_reviewers' => count($pullRequest->requested_reviewers),
                'comment_count' => count($comments),
                'commit_count' => count($commits),
                'state' => $pullRequest->state,
            ];

            if (count($comments) > 0) {
                $firstComment = current($comments);
                $firstCommentAt = new \DateTime($firstComment->created_at);

                $record['time_to_first_comment'] = $firstCommentAt->getTimestamp() - $createdAt->getTimestamp();
            }

            try {
                $this->table->insert($record);
                $records[] = $record;
            } catch (DbException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $jsonResponse = new JsonResponse([
            'errors' => $errors,
            'records' => $records,
        ]);
    }

    private function fetchUrl($url, $options = [], $method = 'get')
    {
        $response = $this->client->request($method, $url, $options);
        return $this->parseJson($response);
    }

    private function parseJson(ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Method that parses array by given path with delimited keys
     *
     * foo.bar.lala.toto
     * foo.bar.*.toto.*.set
     *
     * @param array $array
     * @param array|string $keys
     * @param string $delimiter
     * @throws \Exception
     * @return mixed
     */
    private function parsePath($input, $keys, $delimiter = '.')
    {
        if (is_string($keys)) {
            $keys = explode($delimiter, $keys);
        }
        $current = array_shift($keys);
        if (isset($input[$current])) {
            if (count($keys) > 0) {
                return $this->parsePath($input[$current], $keys);
            }
            return $input[$current];
        }
        if (count($keys) == 0) {
            return $input;
        }
        if ('*' === $current && is_array($input)) {
            $values = [];
            foreach ($input as $value) {
                $values[] = $this->parsePath($value, $keys);
            }
            return $values;
        }
        throw new \Exception(sprintf('Key %s not found', implode($delimiter, $keys)));
    }
}
