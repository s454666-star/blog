<!-- resources/views/snake.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>贪吃蛇游戏</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f0f0f0;
        }

        #gameArea {
            width: 400px;
            height: 400px;
            background-color: #fff;
            border: 2px solid #000;
            position: relative;
            overflow: hidden;
        }

        .snake-part {
            width: 20px;
            height: 20px;
            background-color: green;
            position: absolute;
        }

        .food {
            width: 20px;
            height: 20px;
            background-color: red;
            position: absolute;
        }
    </style>
</head>
<body>
<div id="scoreBoard">Score: 0</div>
<div id="gameArea"></div>

<script>
    const gameArea = document.getElementById('gameArea');
    const gameWidth = gameArea.clientWidth;
    const gameHeight = gameArea.clientHeight;
    const snakeSize = 20;
    let snake = [{x: snakeSize * 5, y: snakeSize * 5}];
    let food = {x: 0, y: 0};
    let direction = {x: 0, y: 0};
    let lastRenderTime = 0;
    const snakeSpeed = 10; // Move 10 times per second

    let score = 0; // 初始化分数

    function updateScore() {
        document.getElementById('scoreBoard').innerText = `Score: ${score}`;
    }

    function main(currentTime) {
        window.requestAnimationFrame(main);
        const secondsSinceLastRender = (currentTime - lastRenderTime) / 1000;
        if (secondsSinceLastRender < 1 / snakeSpeed) return;

        lastRenderTime = currentTime;

        updateGame();
        drawGame();
    }

    window.requestAnimationFrame(main);

    function updateGame() {
        // Update the snake's position
        for (let i = snake.length - 2; i >= 0; i--) {
            snake[i + 1] = {...snake[i]};
        }

        snake[0].x += direction.x * snakeSize;
        snake[0].y += direction.y * snakeSize;

        // Check for food consumption
        if (snake[0].x === food.x && snake[0].y === food.y) {
            snake.push({...snake[snake.length - 1]});
            score += 10; // 增加分数
            console.log(score); // 打印当前分数以调试
            updateScore(); // 更新记分板
            placeFood();
        }

        // Check for game over conditions
        if (snake[0].x < 0 || snake[0].x >= gameWidth || snake[0].y < 0 || snake[0].y >= gameHeight || snakeIntersection()) {
            snake = [{x: snakeSize * 5, y: snakeSize * 5}];
            direction = {x: 0, y: 0}; // Reset the game
        }
    }

    function drawGame() {
        gameArea.innerHTML = '';
        // Draw the snake
        snake.forEach(segment => {
            const snakeElement = document.createElement('div');
            snakeElement.style.gridRowStart = segment.y;
            snakeElement.style.gridColumnStart = segment.x;
            snakeElement.classList.add('snake-part');
            snakeElement.style.left = `${segment.x}px`;
            snakeElement.style.top = `${segment.y}px`;
            gameArea.appendChild(snakeElement);
        });

        // Draw the food
        const foodElement = document.createElement('div');
        foodElement.style.gridRowStart = food.y;
        foodElement.style.gridColumnStart = food.x;
        foodElement.classList.add('food');
        foodElement.style.left = `${food.x}px`;
        foodElement.style.top = `${food.y}px`;
        gameArea.appendChild(foodElement);
    }

    function placeFood() {
        food = {
            x: Math.floor(Math.random() * (gameWidth / snakeSize)) * snakeSize,
            y: Math.floor(Math.random() * (gameHeight / snakeSize)) * snakeSize
        };
    }

    function snakeIntersection() {
        for (let i = 1; i < snake.length - 1; i++) { // 注意这里的终止条件改为 `snake.length - 1`
            if (snake[i].x === snake[0].x && snake[i].y === snake[0].y) {
                return true;
            }
        }
        return false;
    }

    window.addEventListener('keydown', e => {
        switch (e.key) {
            case 'ArrowUp':
                if (direction.y === 0) {
                    direction = {x: 0, y: -1};
                }
                break;
            case 'ArrowDown':
                if (direction.y === 0) {
                    direction = {x: 0, y: 1};
                }
                break;
            case 'ArrowLeft':
                if (direction.x === 0) {
                    direction = {x: -1, y: 0};
                }
                break;
            case 'ArrowRight':
                if (direction.x === 0) {
                    direction = {x: 1, y: 0};
                }
                break;
        }
    });

    placeFood(); // Place the first food item when the game starts
</script>
</body>
</html>
