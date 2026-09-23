/**
 * ページ読込時にいいねボタン生成
 */
document.addEventListener('DOMContentLoaded', initLikeButtons);

function initLikeButtons()
{
    document
        .querySelectorAll('.like-area')
        .forEach(createLikeButton);
}

async function createLikeButton(area)
{
    const pageId = area.dataset.pageId;

    const response =
        await fetch(`/like/api/count.php?page_id=${pageId}`);

    const data = await response.json();

    area.innerHTML = `
        <button class="like-button">♡</button>
        <span class="like-count">${data.count}</span>
    `;

    area.querySelector('.like-button')
        .addEventListener('click', () => sendLike(pageId, area));
}

async function sendLike(pageId, area)
{
    const response = await fetch('/like/api/like.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            page_id: pageId
        })
    });

    const result = await response.json();

    if (!result.success) {
        alert(result.message);
        return;
    }

    area.querySelector('.like-button').textContent = '❤️';

    const countNode =
        area.querySelector('.like-count');

    countNode.textContent =
        Number(countNode.textContent) + 1;
}
